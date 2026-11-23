<?php

declare(strict_types=1);

use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\OrderTimerType;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderTimer;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Storage\Contracts\SecretVault;
use App\Domains\Storage\Models\StorageItem;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;

beforeEach(function (): void {
    $this->fx = CheckoutFixture::build();
    $this->actingAs($this->fx['buyer']);
});

it('shows the checkout with a quote, the payment methods, buyer inputs and an idempotency key', function (): void {
    $this->get('/checkout?offer='.$this->fx['manual']->public_id.'&qty=2')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Checkout/Show')
        ->where('quantity', 2)
        ->where('quote.subtotal', 2000)
        ->where('quote.buyerFee', 60)
        ->where('quote.total', 2060)
        ->where('method', 'fake_card')
        ->has('methods', 2)
        ->where('credits.available', 0)
        ->has('buyerInputs', 2)
        ->where('buyerInputs.0.key', 'player_id')
        ->has('idempotencyKey'));

    $this->get('/checkout?offer='.$this->fx['manual']->public_id.'&method=fake_wallet')->assertInertia(fn (Assert $page) => $page
        ->where('method', 'fake_wallet')
        ->where('quote.methodFee', 25)); // 1.5 % of $10.30 = 15.45¢ → 15¢, + 10¢
});

it('opens a pending-payment order: number, snapshot, sealed buyer inputs, reserved stock, timer, history, PSP redirect', function (): void {
    $response = $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['quantity' => 2]));

    $order = CheckoutFixture::lastOrder($this->fx['buyer']);
    $payment = CheckoutFixture::pspPayment($order);
    $response->assertRedirect($payment->redirect_url);

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->number)->toMatch('/^X2K\d{6}\d{4}$/')
        ->and($order->total_amount)->toBe(2060)
        ->and($order->commission_amount)->toBe(160)
        ->and($order->seller_net_amount)->toBe(1840)
        ->and($order->item->snapshot['title'])->toBe('Coins pack')
        ->and($order->item->reserved_manual_qty)->toBe(2)
        ->and($this->fx['manual']->refresh()->manual_stock_qty)->toBe(3)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount)->toBe(2060)
        ->and($payment->redirect_url)->toStartWith('/fake-psp/fake_')
        ->and(OrderTimer::query()->where('order_id', $order->id)->sole()->type)->toBe(OrderTimerType::PaymentExpiry)
        ->and($order->history()->sole()->to_status)->toBe(OrderStatus::PendingPayment);

    // Buyer inputs are encrypted at rest and only readable through the vault.
    $raw = json_encode(DB::table('orders')->where('id', $order->id)->first(), JSON_THROW_ON_ERROR);
    expect($raw)->not->toContain('12345678')
        ->and(json_decode(app(SecretVault::class)->open($order->sealedBuyerInputs()), true))->toBe(['player_id' => '12345678', 'server' => 'eu'])
        ->and($order->toArray())->not->toHaveKey('buyer_inputs_ciphertext');

    $this->get($order->path())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Buy/Orders/Show')
        ->where('order.status', 'pending_payment')
        ->where('order.payment.redirectUrl', $payment->redirect_url)
        ->where('order.timeline.0.to', 'pending_payment'));
});

it('returns the same order when the form is submitted twice (idempotency key)', function (): void {
    $form = CheckoutFixture::form($this->fx['manual']);

    $this->post('/checkout', $form);
    $this->post('/checkout', $form);

    expect(Order::query()->count())->toBe(1)
        ->and($this->fx['manual']->refresh()->manual_stock_qty)->toBe(4);
});

it('reserves storage items for auto-delivered offers', function (): void {
    $this->post('/checkout', CheckoutFixture::form($this->fx['auto'], ['quantity' => 2]))->assertRedirect();

    $order = CheckoutFixture::lastOrder($this->fx['buyer']);
    $reserved = StorageItem::query()->where('reserved_for_order_item_id', $order->item->id)->get();

    expect($reserved)->toHaveCount(2)
        ->and($reserved->every(fn (StorageItem $i) => $i->status->value === 'reserved' && $i->reserved_until?->equalTo($order->payment_due_at)))->toBeTrue()
        ->and($this->fx['group']->refresh()->available_count_cached)->toBe(1);
});

it('refuses what the offer, the buyer inputs or the rules do not allow', function (array $overrides, string $field): void {
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], $overrides))->assertSessionHasErrors($field);

    expect(Order::query()->count())->toBe(0)
        ->and($this->fx['manual']->refresh()->manual_stock_qty)->toBe(5);
})->with([
    'more than the stock' => [['quantity' => 6], 'quantity'],
    'missing player id' => [['buyer_inputs' => ['server' => 'eu']], 'buyer_inputs.player_id'],
    'malformed player id' => [['buyer_inputs' => ['player_id' => 'abc']], 'buyer_inputs.player_id'],
    'server not in the list' => [['buyer_inputs' => ['player_id' => '12345678', 'server' => 'mars']], 'buyer_inputs.server'],
    'unknown payment method' => [['method' => 'carrier_pigeon'], 'method'],
    'bad idempotency key' => [['idempotency_key' => 'x'], 'idempotency_key'],
]);

it('refuses own offers, unavailable offers, unverified buyers, and big first orders', function (): void {
    $this->actingAs($this->fx['seller']->user);
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual']))->assertSessionHasErrors('checkout');

    $this->actingAs($this->fx['buyer']);
    $this->fx['manual']->forceFill(['status' => OfferStatus::Deactivated])->save();
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual']))->assertSessionHasErrors('checkout');
    $this->get('/checkout?offer='.$this->fx['manual']->public_id)->assertNotFound();

    $big = Offer::factory()->create(['unit_price_amount' => 6000]); // $60 > $50 first-order cap
    $this->post('/checkout', CheckoutFixture::form($big))->assertSessionHasErrors('checkout');

    $this->fx['buyer']->forceFill(['email_verified_at' => null])->save();
    $this->get('/checkout?offer='.$big->public_id)->assertRedirect(route('verification.notice'));

    expect(Order::query()->count())->toBe(0);
});

it('lists purchase orders by buyer tab, only the buyer\'s own', function (): void {
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual']));
    $this->post('/checkout', CheckoutFixture::form($this->fx['auto']));

    $this->get('/buy/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Buy/Orders/Index')
        ->where('counts.all', 2)
        ->where('counts.pending_payment', 2)
        ->where('counts.pending_delivery', 0)
        ->has('orders.items', 2));

    $order = CheckoutFixture::lastOrder($this->fx['buyer']);
    $this->actingAs($this->fx['seller']->user)->get($order->path())->assertNotFound();
});
