<?php

declare(strict_types=1);

use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponClaim;
use App\Domains\Identity\Models\User;
use App\Domains\Points\Support\Points;
use App\Domains\Sellers\Models\SellerProfile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CouponFixture;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('lists platform coupons and lets the buyer claim a free one once', function (): void {
    $coupon = CouponFixture::platform();

    $this->get('/coupons/platform')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Coupons/Center')
        ->where('mode', 'platform')
        ->has('coupons', 1)
        ->where('coupons.0.label', '$3.00')
        ->where('coupons.0.claimed', false));

    $this->post("/coupons/{$coupon->public_id}/claim")->assertSessionHas('success');
    $this->post("/coupons/{$coupon->public_id}/claim")->assertSessionHasErrors('coupon');

    expect(CouponClaim::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($coupon->refresh()->claimed_count)->toBe(1);

    $this->get('/coupons/platform')->assertInertia(fn (Assert $p) => $p
        ->where('coupons.0.claimed', true)
        ->has('claims', 1)
        ->where('claims.0.status', 'available'));
});

it('stops claims at the total limit, below the buyer level, and for ended coupons', function (): void {
    $limited = CouponFixture::platform(['claim_limit_total' => 1]);
    $this->actingAs(User::factory()->create())->post("/coupons/{$limited->public_id}/claim");
    $this->actingAs($this->user)->post("/coupons/{$limited->public_id}/claim")->assertSessionHasErrors('coupon');

    $vip = CouponFixture::platform(['user_level_min' => 99]);
    $this->post("/coupons/{$vip->public_id}/claim")->assertSessionHasErrors('coupon');

    $ended = CouponFixture::platform(['valid_to' => now()->subMinute()]);
    $this->post("/coupons/{$ended->public_id}/claim")->assertSessionHasErrors('coupon');

    expect(CouponClaim::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('sells platform coupons for X2K Score only, never more than the buyer has', function (): void {
    $coupon = CouponFixture::platform(['points_cost' => 500]);

    $this->post("/coupons/{$coupon->public_id}/claim")->assertSessionHasErrors('coupon'); // must use points
    $this->post("/coupons/{$coupon->public_id}/redeem")->assertSessionHasErrors('coupon'); // no points yet
    expect(CouponClaim::query()->count())->toBe(0);

    CouponFixture::givePoints($this->user, 800);
    $this->post("/coupons/{$coupon->public_id}/redeem")->assertSessionHas('success');

    expect(Points::balance($this->user->id))->toBe(300)
        ->and(CouponClaim::query()->sole()->source)->toBe('points');
});

it('shows claimable public store codes grouped by store and claims them, but never your own', function (): void {
    $seller = SellerProfile::factory()->create(['store_name' => 'Pixel Vault']);
    $coupon = CouponFixture::store($seller);
    CouponFixture::store($seller, ['code' => 'HIDDEN1', 'is_public' => false]);

    $this->get('/coupons/shopping')->assertInertia(fn (Assert $p) => $p
        ->has('coupons', 1)
        ->where('coupons.0.code', 'SAVE10')
        ->where('coupons.0.store.name', 'Pixel Vault'));

    $this->post("/coupons/{$coupon->public_id}/claim")->assertSessionHas('success');
    $this->actingAs($seller->user)->post("/coupons/{$coupon->public_id}/claim")->assertSessionHasErrors('coupon');
});

it('claims a store code typed on the store page, including private codes', function (): void {
    $seller = SellerProfile::factory()->create(['slug' => 'pixel-vault']);
    CouponFixture::store($seller, ['code' => 'VIP2026', 'is_public' => false]);

    $this->post('/coupons/code', ['store' => 'pixel-vault', 'code' => 'vip2026'])->assertSessionHas('success');
    $this->post('/coupons/code', ['store' => 'pixel-vault', 'code' => 'NOPE'])->assertSessionHasErrors('coupon');

    expect(CouponClaim::query()->sole()->coupon->code)->toBe('VIP2026');
});

it('lists public store coupons on the store page', function (): void {
    $seller = SellerProfile::factory()->create(['slug' => 'pixel-vault']);
    CouponFixture::store($seller);
    CouponFixture::store($seller, ['code' => 'SECRET1', 'is_public' => false]);

    $this->get('/en/store/pixel-vault')->assertOk()->assertInertia(fn (Assert $p) => $p->has('coupons', 1)->where('coupons.0.code', 'SAVE10'));
});

it('expires unused claims past their date (hourly job)', function (): void {
    $coupon = CouponFixture::platform(['valid_days_after_claim' => 3]);
    $this->post("/coupons/{$coupon->public_id}/claim");

    $this->travel(4)->days();
    $this->artisan('coupons:expire-claims')->assertSuccessful();

    expect(CouponClaim::query()->sole()->status)->toBe(CouponClaim::EXPIRED)
        ->and(Coupon::query()->count())->toBe(1);
});
