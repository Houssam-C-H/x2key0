<?php

declare(strict_types=1);

use App\Domains\Delivery\Actions\DeliverOrder;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Ledger\Models\Hold;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\OrderTimerType;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\OrderTimer;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Points\Support\Points;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerStats;
use Database\Seeders\PointsRuleSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

beforeEach(function (): void {
    (new UserLevelSeeder)->run();
    (new PointsRuleSeeder)->run();
    $this->fx = CheckoutFixture::build();
    $this->buyer = $this->fx['buyer'];
    $this->seller = $this->fx['seller']->user;
    $this->sellerAccount = fn (string $code) => Ledger::owedBy($this->seller, $code);
    $this->delivered = fn (string $offer = 'manual', array $o = []) => CheckoutFixture::deliveredOrder($this->fx, $offer, $o);
});

it('the buyer confirms: ORDER_SETTLEMENT moves escrow to the seller\'s pending balance and the platform\'s commission', function (): void {
    $order = ($this->delivered)(); // $10.00 + $0.30 fee
    expect($order->status)->toBe(OrderStatus::Delivered)->and(Ledger::escrow())->toBe(1000);

    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/confirm")->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->completed_at)->not->toBeNull()
        ->and($order->commission_amount)->toBe(80)   // 8 % of $10.00
        ->and($order->seller_net_amount)->toBe(920)
        ->and(Ledger::escrow())->toBe(0)
        ->and(($this->sellerAccount)('SELLER_PENDING'))->toBe(920)
        ->and(Ledger::revenue('REV_COMMISSION'))->toBe(80)
        ->and(Ledger::feeRevenue())->toBe(30)
        ->and($order->history()->latest('id')->first()?->reason)->toBe('buyer_confirm')
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('only the buyer of a delivered order can confirm it, once', function (): void {
    $order = ($this->delivered)();

    $this->actingAs($this->seller)->post("/buy/orders/{$order->public_id}/confirm")->assertNotFound();
    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/confirm");
    $this->post("/buy/orders/{$order->public_id}/confirm")->assertSessionHasErrors('order');

    expect(LedgerJournal::query()->where('type', 'ORDER_SETTLEMENT')->count())->toBe(1)
        ->and(($this->sellerAccount)('SELLER_PENDING'))->toBe(920);
});

it('an unpaid or undelivered order cannot be confirmed', function (): void {
    $this->actingAs($this->buyer)->post('/checkout', CheckoutFixture::form($this->fx['manual']));
    $order = CheckoutFixture::lastOrder($this->buyer);

    $this->post("/buy/orders/{$order->public_id}/confirm")->assertSessionHasErrors('order');
    expect(fn () => app(OrderStateMachine::class)->complete($order, ActorType::Buyer))->toThrow(CheckoutException::class);
});

it('auto-confirms after the confirmation window, idempotently: "The system auto confirm"', function (): void {
    $order = ($this->delivered)();

    $this->travel(71)->hours();
    Artisan::call('orders:process-timers');
    expect($order->refresh()->status)->toBe(OrderStatus::Delivered);

    $this->travel(2)->hours();
    Artisan::call('orders:process-timers');
    Artisan::call('orders:process-timers');

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->history()->latest('id')->first()?->actor_type)->toBe(ActorType::System)
        ->and($order->history()->latest('id')->first()?->reason)->toBe('auto_confirm')
        ->and(OrderTimer::query()->where('order_id', $order->id)->where('type', OrderTimerType::ConfirmDue)->value('processed_at'))->not->toBeNull()
        ->and(LedgerJournal::query()->where('type', 'ORDER_SETTLEMENT')->count())->toBe(1);
});

it('earns the buyer points once on completion: floor(USD paid × 10 × level multiplier)', function (): void {
    $order = ($this->delivered)();
    expect(Points::balance($this->buyer->id))->toBe(0);

    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/confirm");

    expect(Points::balance($this->buyer->id))->toBe(103); // $10.30
});

it('counts the sale in the seller\'s stats', function (): void {
    $order = ($this->delivered)();
    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/confirm");

    $stats = SellerStats::query()->where('seller_profile_id', $this->fx['seller']->id)->firstOrFail();
    expect($stats->orders_completed)->toBe(1)->and($stats->sales_30d_amount)->toBe(1000);
});

it('holds the net for max(category, level) days plus the new-seller extra, then releases it with the rolling reserve', function (): void {
    $order = ($this->delivered)();
    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/confirm");

    // Top-up category 3 days, new seller level 7 days, + 7 days while the store has < 20 completed orders.
    $hold = Hold::query()->sole();
    expect($hold->kind)->toBe('settlement')->and($hold->amount)->toBe(920)->and($hold->reserve_bps)->toBe(1000)
        ->and($hold->release_at->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($order->refresh()->settle_after?->isSameDay(now()->addDays(14)))->toBeTrue();

    $this->travel(13)->days();
    Artisan::call('holds:release');
    expect(($this->sellerAccount)('SELLER_PENDING'))->toBe(920);

    $this->travel(2)->days();
    Artisan::call('holds:release');
    Artisan::call('holds:release'); // idempotent

    expect(($this->sellerAccount)('SELLER_PENDING'))->toBe(0)
        ->and(($this->sellerAccount)('SELLER_AVAILABLE'))->toBe(828)   // 920 − 10 % reserve
        ->and(($this->sellerAccount)('SELLER_RESERVE'))->toBe(92)
        ->and(Hold::query()->where('kind', 'reserve')->sole()->release_at->isSameDay(now()->addDays(60)))->toBeTrue();

    $this->travel(59)->days();
    Artisan::call('holds:release');
    expect(($this->sellerAccount)('SELLER_RESERVE'))->toBe(92);

    $this->travel(2)->days();
    Artisan::call('holds:release');
    Artisan::call('holds:release');

    expect(($this->sellerAccount)('SELLER_RESERVE'))->toBe(0)
        ->and(($this->sellerAccount)('SELLER_AVAILABLE'))->toBe(920)
        ->and(Hold::query()->whereNull('released_at')->count())->toBe(0)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('no new-seller extra and no reserve for a seasoned top-tier seller', function (): void {
    $this->fx['seller']->forceFill(['seller_level_id' => SellerLevel::query()->where('code', 'diamond')->value('id')])->save();
    SellerStats::query()->where('seller_profile_id', $this->fx['seller']->id)->update(['orders_completed' => 500]);
    $order = ($this->delivered)();
    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/confirm");

    $hold = Hold::query()->sole();
    expect($hold->release_at->isSameDay(now()->addDays(3)))->toBeTrue() // max(top-up 3, diamond 3)
        ->and($order->refresh()->commission_amount)->toBe(60);          // diamond 6 %

    $this->travel(4)->days();
    Artisan::call('holds:release');

    expect(($this->sellerAccount)('SELLER_AVAILABLE'))->toBe(940)
        ->and(Hold::query()->where('kind', 'reserve')->count())->toBe(0);
});

it('insured offers release in equal installments across the insurance period, never before the hold ends', function (): void {
    $this->fx['manual']->forceFill(['insurance_days' => 60])->save();
    $order = ($this->delivered)();
    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/confirm");

    $holds = Hold::query()->where('kind', 'insurance')->orderBy('seq')->get();
    expect($holds->pluck('amount')->all())->toBe([307, 307, 306])  // 920 split exactly, remainder first
        ->and($holds->sum('amount'))->toBe(920)
        ->and($holds->map(fn (Hold $h) => (int) now()->startOfDay()->diffInDays($h->release_at->startOfDay()))->all())->toBe([20, 40, 60]);

    $this->travel(21)->days();
    Artisan::call('holds:release');
    expect(($this->sellerAccount)('SELLER_PENDING'))->toBe(613)->and(($this->sellerAccount)('SELLER_AVAILABLE'))->toBe(276); // 307 − 10 % reserve

    $this->travel(40)->days();
    Artisan::call('holds:release');
    expect(($this->sellerAccount)('SELLER_PENDING'))->toBe(0)->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('account transfers also wait for the recovery-protection window', function (): void {
    $this->fx['manual']->forceFill(['delivery_method' => DeliveryMethod::AccountTransfer, 'account_recovery_protection_days' => 30])->save();
    $order = ($this->delivered)('manual', ['secrets' => 'hero|pw1']);
    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/confirm");

    expect(Hold::query()->sole()->release_at->isSameDay(now()->addDays(30)))->toBeTrue();
});

it('a partly refunded order settles only the units kept', function (): void {
    $this->fx['manual']->forceFill(['delivery_method' => DeliveryMethod::Code])->save();
    $this->actingAs($this->buyer)->post('/checkout', CheckoutFixture::form($this->fx['manual'], ['quantity' => 3]));
    $order = CheckoutFixture::lastOrder($this->buyer);
    CheckoutFixture::webhook($order, PaymentStatus::Captured);

    // Two units delivered, then the buyer stops the rest after the deadline (one unit refunded).
    app(DeliverOrder::class)->handle($order->refresh(), $this->seller, 2, 'A
B', null, null);
    $this->travel(31)->minutes();
    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/cancel-remainder")->assertSessionHasNoErrors();
    expect($order->refresh()->status)->toBe(OrderStatus::Delivered)->and($order->item->refunded_qty)->toBe(1);

    $this->post("/buy/orders/{$order->public_id}/confirm")->assertSessionHasNoErrors();

    expect($order->refresh()->seller_net_amount)->toBe(1840)   // 2 × $10.00 − 8 %
        ->and($order->commission_amount)->toBe(160)
        ->and(Ledger::escrow())->toBe(0)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('shows the seller balances on Sold Orders, equal to the ledger', function (): void {
    $order = ($this->delivered)();
    $this->actingAs($this->buyer)->post("/buy/orders/{$order->public_id}/confirm");
    $this->travel(15)->days();
    Artisan::call('holds:release');

    $this->actingAs($this->seller)->get('/seller/orders')->assertInertia(fn (Assert $p) => $p
        ->where('balances.pending', 0)
        ->where('balances.available', 828)
        ->where('balances.reserve', 92));
});
