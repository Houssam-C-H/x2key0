<?php

declare(strict_types=1);

use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Payments\Enums\PaymentStatus;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

beforeEach(function (): void {
    $this->fx = CheckoutFixture::build();
    $this->buyer = $this->fx['buyer'];
    $this->actingAs($this->buyer);
    $this->checkout = function (array $overrides = [], string $offer = 'manual') {
        $this->post('/checkout', CheckoutFixture::form($this->fx[$offer], $overrides));

        return CheckoutFixture::lastOrder($this->buyer);
    };
    $this->journals = fn () => LedgerJournal::query()->orderBy('id')->pluck('type')->map->value->all();
});

it('card order: PAYMENT_CAPTURED moves the card money into escrow and recognises the fees', function (): void {
    $order = ($this->checkout)(['quantity' => 2]); // 2000 + 60 fee
    CheckoutFixture::webhook($order, PaymentStatus::Captured);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(($this->journals)())->toBe(['PAYMENT_CAPTURED'])
        ->and(Ledger::clearing())->toBe(2060)
        ->and(Ledger::escrow())->toBe(2000)       // the seller-side price
        ->and(Ledger::feeRevenue())->toBe(60)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('credits-only order: CREDITS_SPENT then PAYMENT_CAPTURED; no PSP involved', function (): void {
    Ledger::fundCredits($this->buyer, 5000);

    $this->get('/checkout?offer='.$this->fx['manual']->public_id)->assertInertia(fn (Assert $p) => $p->where('credits.available', 5000));

    $order = ($this->checkout)(['use_credits' => true, 'method' => null]);

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and(($this->journals)())->toBe(['PROMO_CREDIT', 'CREDITS_SPENT', 'PAYMENT_CAPTURED'])
        ->and(Ledger::credits($this->buyer))->toBe(5000 - 1030)
        ->and(Ledger::escrow())->toBe(1000)
        ->and(Ledger::feeRevenue())->toBe(30)
        ->and(Ledger::clearing())->toBe(0);
});

it('mixed order that fails at the PSP: credits come back through CREDITS_RESTORED, escrow ends empty', function (): void {
    Ledger::fundCredits($this->buyer, 400);

    $order = ($this->checkout)(['use_credits' => true]);
    expect(Ledger::credits($this->buyer))->toBe(0)->and(Ledger::escrow())->toBe(400);

    CheckoutFixture::webhook($order, PaymentStatus::Failed);

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(($this->journals)())->toBe(['PROMO_CREDIT', 'CREDITS_SPENT', 'CREDITS_RESTORED'])
        ->and(Ledger::credits($this->buyer))->toBe(400)
        ->and(Ledger::escrow())->toBe(0)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('mixed order that succeeds: escrow holds the seller-side price whatever the split', function (): void {
    Ledger::fundCredits($this->buyer, 980); // most of the subtotal; the card pays the rest (the method minimum is 50¢)

    $order = ($this->checkout)(['use_credits' => true, 'quantity' => 1]);
    expect(CheckoutFixture::pspPayment($order)->amount)->toBe(50);
    CheckoutFixture::webhook($order, PaymentStatus::Captured);

    expect(Ledger::escrow())->toBe(1000)
        ->and(Ledger::feeRevenue())->toBe(30)
        ->and(Ledger::clearing())->toBe(50)
        ->and(Ledger::credits($this->buyer))->toBe(0);
});

it('an expired order captured late: LATE_CAPTURE keeps the money in escrow until it is refunded', function (): void {
    $order = ($this->checkout)();
    $this->travel(31)->minutes();
    Artisan::call('orders:expire-unpaid');

    CheckoutFixture::webhook($order, PaymentStatus::Captured);

    expect(($this->journals)())->toBe(['LATE_CAPTURE'])
        ->and(Ledger::clearing())->toBe(1030)
        ->and(Ledger::escrow())->toBe(1030)
        ->and(Ledger::feeRevenue())->toBe(0);
});

it('a replayed capture posts nothing twice', function (): void {
    $order = ($this->checkout)();
    CheckoutFixture::webhook($order, PaymentStatus::Captured, eventId: 'evt_1');
    CheckoutFixture::webhook($order, PaymentStatus::Captured, eventId: 'evt_2'); // a different event for the same capture

    expect(LedgerJournal::query()->where('type', JournalType::PaymentCaptured)->count())->toBe(1)
        ->and(Ledger::clearing())->toBe(1030);
});

it('ledger:reconcile matches captured payments with PSP clearing, and spots a missing journal', function (): void {
    $order = ($this->checkout)();
    CheckoutFixture::webhook($order, PaymentStatus::Captured);

    expect(Artisan::call('ledger:reconcile', ['provider' => 'fake', 'date' => now()->toDateString()]))->toBe(0);

    // A captured payment the ledger never heard of.
    $other = ($this->checkout)();
    CheckoutFixture::pspPayment($other)->forceFill(['status' => PaymentStatus::Captured, 'captured_at' => now()])->save();

    expect(Artisan::call('ledger:reconcile', ['provider' => 'fake', 'date' => now()->toDateString()]))->toBe(1);
});
