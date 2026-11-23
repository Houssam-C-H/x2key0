<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerStats;
use App\Domains\Violations\Actions\DecideViolation;
use App\Domains\Violations\Actions\RecordViolation;
use App\Domains\Violations\Enums\ViolationStatus;
use App\Domains\Violations\Exceptions\ViolationException;
use App\Domains\Violations\Models\Violation;
use App\Domains\Violations\Models\ViolationRule;
use Database\Seeders\ViolationRuleSeeder;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;

beforeEach(function (): void {
    (new ViolationRuleSeeder)->run();
    $this->fx = CheckoutFixture::build();
    $this->seller = $this->fx['seller'];
    $this->manager = User::factory()->staff(StaffRole::MerchantManager)->create();
});

it('raises seller_cancel and late_delivery automatically, once per order', function (): void {
    $this->actingAs($this->fx['buyer'])->post('/checkout', CheckoutFixture::form($this->fx['manual']));
    $order = CheckoutFixture::lastOrder($this->fx['buyer']);
    CheckoutFixture::webhook($order, PaymentStatus::Captured);
    app(OrderStateMachine::class)->cancel($order->refresh(), 'seller_cancel: out of stock', ActorType::Seller, $this->seller->user);

    $this->actingAs($this->fx['buyer'])->post('/checkout', CheckoutFixture::form($this->fx['manual']));
    $late = CheckoutFixture::lastOrder($this->fx['buyer']);
    CheckoutFixture::webhook($late, PaymentStatus::Captured);
    $this->travel(25)->hours();
    Artisan::call('orders:process-timers');

    $codes = Violation::query()->with('rule')->get()->map(fn (Violation $v): string => $v->rule->code)->sort()->values()->all();
    expect($codes)->toBe(['late_delivery', 'seller_cancel'])
        ->and(Violation::query()->first()?->status)->toBe(ViolationStatus::Open);

    expect(app(RecordViolation::class)->auto('seller_cancel', $this->seller->id, orderId: $order->id)?->id)
        ->toBe(Violation::query()->where('order_id', $order->id)->value('id'));
});

it('appeal → revoke; the appeal window closes; upheld after the window by the hourly job', function (): void {
    $v = app(RecordViolation::class)->auto('late_delivery', $this->seller->id, orderId: null, details: 'test');

    $this->actingAs($this->seller->user)->get('/seller/violations')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Seller/Violations')
        ->where('counts.open', 1)
        ->where('rows.0.canAppeal', true));

    $this->post("/seller/violations/{$v->public_id}/appeal", ['text' => 'The buyer asked me to wait for stock.'])->assertSessionHasNoErrors();
    expect($v->refresh()->status)->toBe(ViolationStatus::Appealed);

    app(DecideViolation::class)->revoke($v, $this->manager, 'Agreed with buyer in chat.');
    expect($v->refresh()->status)->toBe(ViolationStatus::Revoked);

    $w = app(RecordViolation::class)->auto('seller_cancel', $this->seller->id, orderId: null);
    $this->travel(8)->days();
    $this->post("/seller/violations/{$w->public_id}/appeal", ['text' => 'Too late but please review.'])->assertSessionHasErrors('appeal');

    Artisan::call('violations:process');
    Artisan::call('violations:process');
    expect($w->refresh()->status)->toBe(ViolationStatus::Upheld)
        ->and(SellerStats::query()->where('seller_profile_id', $this->seller->id)->value('violation_points'))->toBe(3);
});

it('suspends the store at the points threshold and bans for a ban rule; staff cannot decide their own store', function (): void {
    config(['x2key.policy.violation_points_store_suspend' => 10]);
    $rule = ViolationRule::query()->where('code', 'off_platform_contact')->sole(); // 10 points, warning

    $v = app(RecordViolation::class)->manual($rule, $this->seller, $this->manager, 'Phone number in chat');
    app(DecideViolation::class)->uphold($v, $this->manager);

    expect($this->seller->refresh()->status)->toBe(SellerStatus::Suspended)
        ->and($this->seller->status_reason)->toContain('violation:off_platform_contact');

    $fraud = app(RecordViolation::class)->manual(ViolationRule::query()->where('code', 'fraud')->sole(), $this->seller, $this->manager, 'Chargeback ring');
    app(DecideViolation::class)->uphold($fraud, $this->manager);
    expect($this->seller->refresh()->status)->toBe(SellerStatus::Banned);

    $own = app(RecordViolation::class)->manual($rule, $this->seller, $this->manager, 'x');
    expect(fn () => app(DecideViolation::class)->uphold($own, $this->seller->user))->toThrow(ViolationException::class);
});

it('a listing-suspension rule takes the offer down', function (): void {
    $rule = ViolationRule::query()->where('code', 'prohibited_listing')->sole();
    $v = app(RecordViolation::class)->manual($rule, $this->seller, $this->manager, 'Prohibited item', offerId: $this->fx['manual']->id);

    app(DecideViolation::class)->uphold($v, $this->manager, 'Confirmed');

    expect($this->fx['manual']->refresh()->status)->toBe(OfferStatus::SystemDeactivated);
});

it('opens the violation admin screens for a merchant manager with 2FA', function (string $path): void {
    $this->actingAs(User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create())->get($path)->assertOk();
})->with(['/admin/sellers/violations', '/admin/sellers/violation-rules']);
