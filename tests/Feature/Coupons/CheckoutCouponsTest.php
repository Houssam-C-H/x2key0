<?php

declare(strict_types=1);

use App\Domains\Coupons\Actions\ClaimCoupon;
use App\Domains\Coupons\Models\CouponClaim;
use App\Domains\Coupons\Models\CouponRedemption;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Support\Accounts;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Enums\PaymentStatus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;
use Tests\Support\CouponFixture;
use Tests\Support\Ledger;

beforeEach(function (): void {
    $this->fx = CheckoutFixture::build();
    $this->buyer = $this->fx['buyer'];
    $this->actingAs($this->buyer);

    // 10 % store coupon (unit 10.00 → 9.00) and a $3 platform coupon.
    $this->storeClaim = app(ClaimCoupon::class)->claim($this->buyer, CouponFixture::store($this->fx['seller']));
    $this->platformClaim = app(ClaimCoupon::class)->claim($this->buyer, CouponFixture::platform());
});

it('pre-selects the best store and platform coupon on the checkout page and prices them', function (): void {
    $this->get('/checkout?offer='.$this->fx['manual']->public_id)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('coupons.eligible', 2)
        ->where('coupons.selected', fn ($ids) => collect($ids)->sort()->values()->all() === collect([$this->storeClaim->public_id, $this->platformClaim->public_id])->sort()->values()->all())
        ->where('quote.listUnitPrice', 1000)
        ->where('quote.unitPrice', 900)
        ->where('quote.discountSeller', 100)
        ->where('quote.discountPlatform', 300));

    // "Do not use a coupon".
    $this->get('/checkout?coupons=none&offer='.$this->fx['manual']->public_id)->assertInertia(fn (Assert $p) => $p
        ->where('coupons.selected', [])
        ->where('quote.unitPrice', 1000)
        ->where('quote.discountPlatform', 0));
});

it('redeems both claims on the order, funds the platform share at capture, and the ledger balances', function (): void {
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['coupons' => [$this->storeClaim->public_id, $this->platformClaim->public_id]]))->assertRedirect();
    $order = CheckoutFixture::lastOrder($this->buyer);

    expect($order->item->unit_price_amount)->toBe(900)
        ->and($order->discount_seller_amount)->toBe(100)
        ->and($order->discount_platform_amount)->toBe(300)
        ->and(CouponRedemption::query()->where('order_id', $order->id)->count())->toBe(2)
        ->and($this->storeClaim->refresh()->status)->toBe(CouponClaim::USED)
        ->and($this->platformClaim->refresh()->used_order_id)->toBe($order->id);

    CheckoutFixture::webhook($order, PaymentStatus::Captured);

    // Escrow holds the full seller-side price: buyer paid 900 − 300, the platform funded 300.
    expect(Ledger::escrow())->toBe(900)
        ->and(Accounts::balance(AccountRef::platform(AccountCode::ExpPromotions, 'USD')))->toBe(300)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('refuses a used claim, a claim of someone else, and two coupons that cannot be combined', function (): void {
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['coupons' => [$this->platformClaim->public_id]]));
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['coupons' => [$this->platformClaim->public_id]]))->assertSessionHasErrors('coupons');

    $solo = app(ClaimCoupon::class)->claim($this->buyer, CouponFixture::platform(['name' => ['en' => 'Solo'], 'stackable' => false]));
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['coupons' => [$this->storeClaim->public_id, $solo->public_id]]))->assertSessionHasErrors('coupons');

    $other = User::factory()->create();
    $theirs = app(ClaimCoupon::class)->claim($other, CouponFixture::platform(['name' => ['en' => 'Theirs']]));
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['coupons' => [$theirs->public_id]]))->assertSessionHasErrors('coupons');
});

it('does not apply a store coupon in another store or below its minimum order', function (): void {
    // Steam key offer (USD 4.00) is sold by the same seller, but this coupon needs USD 50.
    $big = app(ClaimCoupon::class)->claim($this->buyer, CouponFixture::platform(['name' => ['en' => 'Big'], 'min_order_amount' => 5000]));

    $this->get('/checkout?offer='.$this->fx['auto']->public_id)->assertInertia(fn (Assert $p) => $p
        ->where('coupons.eligible', fn ($list) => ! collect($list)->pluck('id')->contains($big->public_id)));
});

it('gives the claims back when the order is cancelled, and promotions get their share back on refund', function (): void {
    $this->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['coupons' => [$this->storeClaim->public_id, $this->platformClaim->public_id]]));
    $order = CheckoutFixture::lastOrder($this->buyer);
    CheckoutFixture::webhook($order, PaymentStatus::Captured);

    app(OrderStateMachine::class)->cancel($order->refresh(), 'seller_cancel: test', ActorType::Seller, $this->fx['seller']->user);

    expect($this->storeClaim->refresh()->status)->toBe(CouponClaim::AVAILABLE)
        ->and($this->platformClaim->refresh()->status)->toBe(CouponClaim::AVAILABLE)
        ->and(CouponRedemption::query()->whereNotNull('reversed_at')->count())->toBe(2)
        ->and($order->refresh()->refunded_discount_platform_amount)->toBe(300)
        ->and(Ledger::escrow())->toBe(0)
        ->and(Accounts::balance(AccountRef::platform(AccountCode::ExpPromotions, 'USD')))->toBe(0)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});
