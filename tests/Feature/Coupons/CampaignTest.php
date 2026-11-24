<?php

declare(strict_types=1);

use App\Domains\Announcements\Models\InboxItem;
use App\Domains\Campaigns\Actions\CampaignParticipations;
use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Campaigns\Models\CampaignParticipation;
use App\Domains\Campaigns\Support\CampaignBadges;
use App\Domains\Coupons\Exceptions\CouponException;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Support\OfferPresenter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;

beforeEach(function (): void {
    $this->fx = CheckoutFixture::build();
    $this->seller = $this->fx['seller'];
    $this->campaign = Campaign::query()->create([
        'name' => ['en' => 'Spring sale'],
        'type' => 'flash_sale',
        'max_offers_per_seller' => 2,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addWeek(),
        'status' => 'active',
    ]);
});

it('lets a seller apply with live offers, and shows the application', function (): void {
    $this->actingAs($this->seller->user)
        ->post("/seller/campaigns/{$this->campaign->id}/apply", ['offers' => [$this->fx['manual']->public_id]])
        ->assertSessionHas('success');

    $this->get('/seller/campaigns')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Seller/Campaigns/Index')
        ->where('campaigns.0.participation.status', 'applied')
        ->where('campaigns.0.participation.offers', [$this->fx['manual']->public_id]));
});

it('refuses too many offers, other sellers\' offers and closed campaigns', function (): void {
    $this->actingAs($this->seller->user);
    $extra = Offer::factory()->create(['seller_profile_id' => $this->seller->id]);
    $three = [$this->fx['manual']->public_id, $this->fx['auto']->public_id, $extra->public_id];
    $this->post("/seller/campaigns/{$this->campaign->id}/apply", ['offers' => $three])->assertSessionHasErrors('offers');

    $foreign = Offer::factory()->create();
    $this->post("/seller/campaigns/{$this->campaign->id}/apply", ['offers' => [$foreign->public_id]])->assertSessionHasErrors('offers');

    $this->campaign->update(['ends_at' => now()->subMinute()]);
    $this->post("/seller/campaigns/{$this->campaign->id}/apply", ['offers' => [$this->fx['manual']->public_id]])->assertSessionHasErrors('offers');

    expect(CampaignParticipation::query()->count())->toBe(0);
});

it('badges approved offers while the campaign runs, and tells the seller', function (): void {
    $participation = app(CampaignParticipations::class)->apply($this->seller, $this->campaign, [$this->fx['manual']->public_id]);
    $staff = User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create();

    $badges = app(CampaignBadges::class);
    expect($badges->for($this->fx['manual']->id))->toBeNull();

    app(CampaignParticipations::class)->decide($participation, true, $staff);
    $badges->flush();

    $offer = Offer::query()->with(OfferPresenter::publicRelations())->findOrFail($this->fx['manual']->id);
    expect(OfferPresenter::card($offer, 'en')['campaign'])->toBe('flash_sale')
        ->and($badges->for($this->fx['auto']->id))->toBeNull()
        ->and(InboxItem::query()->where('user_id', $this->seller->user_id)->value('notification_type'))->toBe('campaign_approved');

    // Ended campaign: no badge.
    $this->campaign->update(['ends_at' => now()->subMinute()]);
    $badges->flush();
    expect($badges->for($this->fx['manual']->id))->toBeNull();
});

it('needs a reason to reject, decides once, and lets the seller re-apply after a rejection', function (): void {
    $participation = app(CampaignParticipations::class)->apply($this->seller, $this->campaign, [$this->fx['manual']->public_id]);
    $staff = User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create();
    $decide = app(CampaignParticipations::class);

    expect(fn () => $decide->decide($participation, false, $staff, ''))->toThrow(CouponException::class);
    $decide->decide($participation, false, $staff, 'Prices are not discounted.');
    expect(fn () => $decide->decide($participation->refresh(), true, $staff))->toThrow(CouponException::class);

    $again = $decide->apply($this->seller, $this->campaign, [$this->fx['auto']->public_id]);
    expect($again->id)->toBe($participation->id)->and($again->status)->toBe('applied')->and($again->reject_reason)->toBeNull();
});

it('opens the marketing admin to merchant managers and keeps finance out', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create());
    $this->get('/admin/platform-coupons')->assertOk();
    $this->get('/admin/platform-coupons/create')->assertOk();
    $this->get('/admin/campaigns')->assertOk();
    $this->get('/admin/campaign-applications')->assertOk();

    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create())->get('/admin/platform-coupons')->assertForbidden();
});
