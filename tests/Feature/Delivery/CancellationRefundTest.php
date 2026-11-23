<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Actions\RefundOrder;
use App\Domains\Payments\Actions\ReviewPayment;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Refund;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerStats;
use App\Domains\Storage\Models\StorageItem;
use App\Shared\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

beforeEach(function (): void {
    $this->fx = CheckoutFixture::build();
    $this->buyer = $this->fx['buyer'];
    $this->seller = $this->fx['seller']->user;
    $this->paid = function (array $overrides = []) {
        $this->actingAs($this->buyer)->post('/checkout', CheckoutFixture::form($this->fx['manual'], $overrides));
        $order = CheckoutFixture::lastOrder($this->buyer);
        CheckoutFixture::webhook($order, PaymentStatus::Captured);

        return $order->refresh();
    };
});

it('the seller cancels a paid order: full refund to the card, stock back, ledger clean, seller stat counted', function (): void {
    $order = ($this->paid)(['quantity' => 2]);
    expect(Ledger::escrow())->toBe(2000);

    $this->actingAs($this->seller)->post("/seller/orders/{$order->public_id}/cancel", ['reason' => 'Out of game currency'])->assertSessionHasNoErrors();

    $order->refresh();
    $refund = Refund::query()->sole();
    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancel_reason)->toStartWith('seller_cancel')
        ->and($refund->destination)->toBe('original_method')
        ->and($refund->amount)->toBe(2060)
        ->and($refund->status)->toBe('succeeded')
        ->and($order->refunded_psp_amount)->toBe(2060)
        ->and($this->fx['manual']->refresh()->manual_stock_qty)->toBe(5)
        ->and(Ledger::escrow())->toBe(0)
        ->and(Ledger::feeRevenue())->toBe(0)
        ->and(Ledger::clearing())->toBe(0)
        ->and(SellerStats::query()->where('seller_profile_id', $this->fx['seller']->id)->value('orders_cancelled_by_seller'))->toBe(1)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);

    // A cancelled order cannot be cancelled or delivered again.
    $this->post("/seller/orders/{$order->public_id}/cancel", ['reason' => 'again'])->assertSessionHasErrors('order');
    expect(Refund::query()->count())->toBe(1);
});

it('refunds proportionally to the sources: credits part to credits, card part to the card', function (): void {
    Ledger::fundCredits($this->buyer, 400);
    $order = ($this->paid)(['use_credits' => true]); // 1030 total: 400 credits + 630 card
    expect($order->paid_credits_amount)->toBe(400)->and($order->paid_psp_amount)->toBe(630);

    $this->actingAs($this->seller)->post("/seller/orders/{$order->public_id}/cancel", ['reason' => 'No stock']);

    $refunds = Refund::query()->orderBy('id')->get();
    expect($refunds->map(fn ($r) => [$r->destination, $r->amount])->all())->toBe([['original_method', 630], ['credits', 400]])
        ->and(Ledger::credits($this->buyer))->toBe(400)
        ->and(Ledger::clearing())->toBe(0)
        ->and(Ledger::escrow())->toBe(0)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('the buyer can choose to get everything as credits', function (): void {
    $order = ($this->paid)();
    $this->travel(31)->minutes(); // delivery deadline passed (30 min)

    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/cancel", ['to_credits' => true])->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(Refund::query()->sole()->destination)->toBe('credits')
        ->and(Ledger::credits($this->buyer))->toBe(1030)
        ->and(Ledger::clearing())->toBe(1030) // the card money stays with us until it is paid out as credits
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('the buyer cannot cancel before the delivery deadline, nor after something was delivered', function (): void {
    $order = ($this->paid)();

    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/cancel")->assertSessionHasErrors('order');
    expect($order->refresh()->status)->toBe(OrderStatus::Paid);

    $this->travel(31)->minutes();
    $this->post("/buy/orders/{$order->public_id}/cancel")->assertSessionHasNoErrors();
    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('auto-cancels a paid order nothing was delivered for once the SLA grace ends; idempotent', function (): void {
    $order = ($this->paid)(); // delivery 30 min + 24 h grace

    $this->travel(24)->hours();
    Artisan::call('orders:process-timers');
    expect($order->refresh()->status)->toBe(OrderStatus::Paid);

    $this->travel(31)->minutes();
    Artisan::call('orders:process-timers');
    Artisan::call('orders:process-timers');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancel_reason)->toBe('auto_cancel_sla')
        ->and(Refund::query()->count())->toBe(1)
        ->and($order->history()->count())->toBe(3)
        ->and(Ledger::escrow())->toBe(0)
        ->and($this->fx['manual']->refresh()->manual_stock_qty)->toBe(5);
});

it('a paid, undelivered auto order that is cancelled frees its storage items again', function (): void {
    $this->actingAs($this->buyer)->post('/checkout', CheckoutFixture::form($this->fx['auto'], ['quantity' => 2]));
    $order = CheckoutFixture::lastOrder($this->buyer);
    StorageItem::query()->where('reserved_for_order_item_id', $order->item->id)->get(); // reserved, payment pending
    CheckoutFixture::webhook($order, PaymentStatus::Captured); // auto-delivered right away

    // Delivered orders cannot be cancelled: the secrets are out.
    $this->actingAs($this->fx['seller']->user)->post("/seller/orders/{$order->public_id}/cancel", ['reason' => 'x'])->assertSessionHasErrors('order');
    expect($order->refresh()->status)->toBe(OrderStatus::Delivered)->and(Refund::query()->count())->toBe(0);
});

it('rejecting a payment review refunds the buyer in full through the PSP', function (): void {
    $this->buyer->forceFill(['created_at' => now()->subHour()])->save();
    app(Settings::class)->set('risk_review_new_account_amount', 100);
    $order = ($this->paid)();
    expect($order->status)->toBe(OrderStatus::PaymentReview);

    $finance = User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create();
    app(ReviewPayment::class)->reject($order, $finance, 'Stolen card');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(Refund::query()->sole()->amount)->toBe(1030)
        ->and(Ledger::clearing())->toBe(0)
        ->and(Ledger::escrow())->toBe(0);
});

it('keeps suspended sellers read-only on orders', function (): void {
    $order = ($this->paid)();
    $this->fx['seller']->forceFill(['status' => SellerStatus::Suspended])->save();

    $this->actingAs($this->seller)->post("/seller/orders/{$order->public_id}/cancel", ['reason' => 'x'])->assertForbidden();
    $this->post("/seller/orders/{$order->public_id}/deliver", ['quantity' => 1, 'note' => 'x'])->assertForbidden();
    $this->get("/seller/orders/{$order->public_id}")->assertOk();
});

it('shows the delivery logs of Storage to the seller', function (): void {
    $this->actingAs($this->buyer)->post('/checkout', CheckoutFixture::form($this->fx['auto']));
    CheckoutFixture::webhook(CheckoutFixture::lastOrder($this->buyer), PaymentStatus::Captured);

    unlockPrivacy($this->seller);
    $this->actingAs($this->seller)->get('/seller/storage/logs')->assertOk()->assertInertia(fn ($p) => $p
        ->component('Storage/Logs')->where('logs.total', 1)->where('logs.items.0.result', 'success')->where('logs.items.0.group', 'Keys'));
});

it('cannot refund more than the order paid: units, sources and fees stay bounded', function (): void {
    $order = ($this->paid)(['quantity' => 2]);
    $item = $order->item;
    $refunds = app(RefundOrder::class);

    expect(fn () => $refunds->handle($order, $item, 3, 'too many', ActorType::Staff, null))
        ->toThrow(CheckoutException::class);

    DB_refund_each_unit($refunds, $order);

    expect($order->refresh()->refunded_psp_amount)->toBe($order->paid_psp_amount)
        ->and($order->refunded_fee_amount)->toBeLessThanOrEqual($order->fees())
        ->and(Ledger::escrow())->toBe(0)
        ->and(Ledger::clearing())->toBe(0);
});

function DB_refund_each_unit(RefundOrder $refunds, Order $order): void
{
    DB::transaction(function () use ($refunds, $order): void {
        $o = Order::query()->lockForUpdate()->findOrFail($order->id);
        $item = $o->item;
        foreach ([1, 1] as $units) {
            $refunds->handle($o, $item->refresh(), $units, 'staff', ActorType::Staff, null);
            $o->refresh();
        }
    });
}
