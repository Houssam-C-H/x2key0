<?php

declare(strict_types=1);

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\OrderTimerType;
use App\Domains\Orders\Enums\SellerSubstatus;
use App\Domains\Orders\Events\OrderPaid;
use App\Domains\Orders\Models\OrderTimer;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\PaymentEvent;
use App\Domains\Payments\Providers\FakePaymentProvider;
use App\Domains\Risk\Models\RiskFlag;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\Support\CheckoutFixture;

beforeEach(function (): void {
    $this->fx = CheckoutFixture::build();
    $this->actingAs($this->fx['buyer']);
    $this->checkout = function (string $offer = 'manual', array $overrides = []) {
        $this->post('/checkout', CheckoutFixture::form($this->fx[$offer], $overrides));

        return CheckoutFixture::lastOrder($this->fx['buyer']);
    };
});

it('moves the order to paid on a captured webhook: substatus, timers, history, event, items held for delivery', function (): void {
    Event::fake([OrderPaid::class]);
    $order = ($this->checkout)('manual');

    CheckoutFixture::webhook($order, PaymentStatus::Captured)->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->seller_substatus)->toBe(SellerSubstatus::New)
        ->and($order->paid_psp_amount)->toBe($order->total_amount)
        ->and($order->paid_at)->not->toBeNull()
        ->and(CheckoutFixture::pspPayment($order)->status)->toBe(PaymentStatus::Captured)
        ->and(OrderTimer::query()->where('order_id', $order->id)->pluck('type')->map->value->sort()->values()->all())
        ->toBe(['accept_due', 'auto_cancel', 'delivery_due', 'payment_expiry'])
        ->and(OrderTimer::query()->where('order_id', $order->id)->where('type', OrderTimerType::PaymentExpiry)->value('processed_at'))->not->toBeNull()
        ->and($order->history()->orderBy('id')->get()->map(fn ($h) => $h->to_status->value)->all())->toBe(['pending_payment', 'paid'])
        ->and($this->fx['manual']->refresh()->manual_stock_qty)->toBe(4);

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e) => $e->orderId === $order->id);
});

it('processes a replayed webhook once', function (): void {
    $order = ($this->checkout)();

    CheckoutFixture::webhook($order, PaymentStatus::Captured, eventId: 'evt_same')->assertJson(['duplicate' => false]);
    CheckoutFixture::webhook($order, PaymentStatus::Captured, eventId: 'evt_same')->assertOk()->assertJson(['duplicate' => true]);

    expect(PaymentEvent::query()->where('provider_event_id', 'evt_same')->count())->toBe(1)
        ->and($order->history()->count())->toBe(2);
});

it('rejects a bad signature (recorded, never processed)', function (): void {
    $order = ($this->checkout)();

    CheckoutFixture::webhook($order, PaymentStatus::Captured, signature: str_repeat('0', 64))->assertStatus(400);

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(PaymentEvent::query()->where('signature_valid', false)->count())->toBe(1);
});

it('flags an amount or currency mismatch and does not move the order', function (array $mismatch): void {
    $order = ($this->checkout)();

    CheckoutFixture::webhook($order, PaymentStatus::Captured, ...$mismatch)->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(CheckoutFixture::pspPayment($order)->status)->toBe(PaymentStatus::Pending)
        ->and(RiskFlag::query()->where('rule', 'payment_mismatch')->count())->toBe(1)
        ->and(PaymentEvent::query()->latest('id')->first()?->error)->toBe('amount_mismatch');
})->with([
    'amount' => [['amount' => 1]],
    'currency' => [['currency' => 'EUR']],
]);

it('cancels on a declined payment and gives the stock back', function (): void {
    $order = ($this->checkout)('manual', ['quantity' => 3]);
    expect($this->fx['manual']->refresh()->manual_stock_qty)->toBe(2);

    CheckoutFixture::webhook($order, PaymentStatus::Failed)->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancel_reason)->toBe('payment_failed')
        ->and($this->fx['manual']->refresh()->manual_stock_qty)->toBe(5)
        ->and($order->item->refresh()->reserved_manual_qty)->toBe(0);

    // A failed payment is final: a later "captured" for it is stale and never revives the order.
    CheckoutFixture::webhook($order, PaymentStatus::Captured);
    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(CheckoutFixture::pspPayment($order)->status)->toBe(PaymentStatus::Failed);
});

it('pays through the fake PSP hosted page end to end', function (): void {
    $order = ($this->checkout)();
    $page = CheckoutFixture::pspPayment($order)->redirect_url;

    $this->get($page)->assertOk()->assertInertia(fn ($p) => $p->component('Checkout/FakePsp')->where('amount', $order->total_amount));
    $this->post($page.'/pay')->assertRedirect($order->path());

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
    $this->post($page.'/pay')->assertNotFound(); // already completed
});

it('recovers a lost webhook with payments:sync-pending, idempotently', function (): void {
    $order = ($this->checkout)();
    $payment = CheckoutFixture::pspPayment($order);
    app(FakePaymentProvider::class)->complete((string) $payment->provider_payment_id, PaymentStatus::Captured); // PSP captured, webhook lost

    $this->travel(10)->minutes();
    Artisan::call('payments:sync-pending');
    Artisan::call('payments:sync-pending');

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(PaymentEvent::query()->where('provider_event_id', "sync:{$payment->public_id}:captured")->count())->toBe(1)
        ->and($order->history()->count())->toBe(2);
});

it('expires unpaid orders, releases stock, and flags a capture that arrives too late', function (): void {
    $manual = ($this->checkout)('manual', ['quantity' => 2]);
    $auto = ($this->checkout)('auto');

    $this->travel(29)->minutes();
    Artisan::call('orders:expire-unpaid');
    expect($manual->refresh()->status)->toBe(OrderStatus::PendingPayment);

    $this->travel(2)->minutes();
    Artisan::call('orders:expire-unpaid');
    Artisan::call('orders:expire-unpaid');

    expect($manual->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($manual->cancel_reason)->toBe('payment_expired')
        ->and($manual->history()->count())->toBe(2)
        ->and($this->fx['manual']->refresh()->manual_stock_qty)->toBe(5)
        ->and($auto->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(CheckoutFixture::availableKeys($this->fx['group']))->toBe(3)
        ->and($this->fx['group']->refresh()->available_count_cached)->toBe(3)
        ->and(CheckoutFixture::pspPayment($manual)->status)->toBe(PaymentStatus::Cancelled);

    // The PSP captured anyway: money moved, so it is recorded and flagged for refund; the order stays closed.
    CheckoutFixture::webhook($manual, PaymentStatus::Captured)->assertOk();
    expect($manual->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(CheckoutFixture::pspPayment($manual)->status)->toBe(PaymentStatus::Captured)
        ->and(RiskFlag::query()->where('rule', 'late_capture')->where('subject_id', $manual->id)->exists())->toBeTrue();
});

it('releases storage items left reserved by a cancelled order (safety net)', function (): void {
    $order = ($this->checkout)('auto');
    // Simulate a crash after the order closed but before its items were released.
    $order->forceFill(['status' => OrderStatus::Cancelled])->save();
    $this->travel(31)->minutes();

    Artisan::call('storage:release-expired-reservations');

    expect(CheckoutFixture::availableKeys($this->fx['group']))->toBe(3)
        ->and($this->fx['group']->refresh()->available_count_cached)->toBe(3);
});

it('keeps the last unit for one buyer only', function (): void {
    $this->fx['manual']->forceFill(['manual_stock_qty' => 1])->save();

    ($this->checkout)();
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual']))->assertSessionHasErrors('checkout');

    expect($this->fx['manual']->refresh()->manual_stock_qty)->toBe(0)
        ->and($this->fx['manual']->status->value)->toBe('out_of_stock');
});
