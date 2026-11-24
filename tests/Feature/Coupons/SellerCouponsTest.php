<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerServiceApplication;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seller = SellerProfile::factory()->create();
    $this->gc = GameCategory::factory()->create();
    $application = new SellerServiceApplication(['seller_profile_id' => $this->seller->id, 'game_category_id' => $this->gc->id]);
    $application->forceFill(['status' => ServiceApplicationStatus::Passed])->save();
    $this->actingAs($this->seller->user);
    $this->form = fn (array $o = []): array => [
        'codes' => "spring10\nSPRING-VIP",
        'percent' => 10,
        'game_category_id' => null,
        'use_limit' => 50,
        'expires_at' => now()->addMonth()->toDateString(),
        'is_public' => true,
        ...$o,
    ];
});

it('creates several upper-case codes at once and lists them with use counts', function (): void {
    $this->post('/seller/coupons', ($this->form)())->assertSessionHas('success');

    expect(Coupon::query()->orderBy('code')->pluck('code')->all())->toBe(['SPRING-VIP', 'SPRING10'])
        ->and(Coupon::query()->first()->value)->toBe(1000);

    $this->get('/seller/coupons')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Seller/Coupons/Index')
        ->where('private', false)
        ->has('coupons', 2)
        ->where('coupons.0.limit', 50)
        ->where('coupons.0.used', 0));

    // Private codes live on their own page.
    $this->get('/seller/coupons/private')->assertInertia(fn (Assert $p) => $p->where('private', true)->has('coupons', 0));
});

it('refuses bad codes, duplicates, a too-high discount, a past expiry and categories the store is not approved for', function (): void {
    $this->post('/seller/coupons', ($this->form)(['codes' => 'ab']))->assertSessionHasErrors('codes');
    $this->post('/seller/coupons', ($this->form)(['percent' => 95]))->assertSessionHasErrors('percent');
    $this->post('/seller/coupons', ($this->form)(['expires_at' => now()->subDay()->toDateString()]))->assertSessionHasErrors('codes');
    $this->post('/seller/coupons', ($this->form)(['game_category_id' => GameCategory::factory()->create()->id]))->assertSessionHasErrors('codes');

    $this->post('/seller/coupons', ($this->form)(['codes' => 'ONCE1']));
    $this->post('/seller/coupons', ($this->form)(['codes' => 'once1']))->assertSessionHasErrors('codes');

    expect(Coupon::query()->count())->toBe(1);
});

it('limits a code to an approved game category', function (): void {
    $this->post('/seller/coupons', ($this->form)(['codes' => 'GAME10', 'game_category_id' => $this->gc->id]))->assertSessionHas('success');

    expect(Coupon::query()->sole()->scope)->toBe(['game_category_ids' => [$this->gc->id]]);
});

it('extends and ends codes, only the seller\'s own', function (): void {
    $this->post('/seller/coupons', ($this->form)(['codes' => 'MINE1']));
    $coupon = Coupon::query()->sole();

    $this->post("/seller/coupons/{$coupon->public_id}/extend", ['expires_at' => now()->addMonths(3)->toDateString()])->assertSessionHas('success');
    expect($coupon->refresh()->valid_to->isAfter(now()->addMonths(2)))->toBeTrue();

    $this->post('/seller/coupons/end', ['ids' => [$coupon->public_id]])->assertSessionHas('success');
    expect($coupon->refresh()->status)->toBe('ended')->and($coupon->isLive())->toBeFalse();

    $other = SellerProfile::factory()->create();
    $this->actingAs($other->user)->post('/seller/coupons/end', ['ids' => [$coupon->public_id]])->assertSessionHasErrors('ids');
});

it('keeps buyers out of the seller coupon pages', function (): void {
    $this->actingAs(User::factory()->create())->get('/seller/coupons')->assertRedirect();
});
