<?php

declare(strict_types=1);

use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Models\OfferPriceHistory;
use App\Domains\Offers\Models\OfferStatusChange;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OfferFixture;

beforeEach(function (): void {
    $this->fx = OfferFixture::build();
    $this->actingAs($this->fx['seller']);
    $this->own = fn (array $attrs = []) => Offer::factory()->create([
        'seller_profile_id' => $this->fx['profile']->id,
        'game_category_id' => $this->fx['catalog']['gameCategory']->id,
        ...$attrs,
    ]);
});

it('lists the seller\'s offers with tab counts, service chips, filters and sort', function (): void {
    $a = ($this->own)(['title' => 'Alpha key', 'unit_price_amount' => 500]);
    ($this->own)(['title' => 'Beta key', 'unit_price_amount' => 900, 'expires_at' => now()->addHours(5)]);
    ($this->own)(['title' => 'Old key', 'status' => OfferStatus::Deactivated]);
    ($this->own)(['title' => 'Waiting', 'status' => OfferStatus::PendingReview]);
    Offer::factory()->create(['title' => 'Someone else']);

    $this->get('/seller/offers')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Seller/Offers/Index')
        ->where('offers.total', 4)
        ->where('counts.all', 4)
        ->where('counts.active', 2)
        ->where('counts.expire_soon', 1)
        ->where('counts.deactivated', 1)
        ->where('counts.in_review', 1)
        ->where('services.0.count', 4));

    $this->get('/seller/offers?tab=active&sort=lowest')->assertInertia(fn (Assert $page) => $page
        ->where('offers.total', 2)
        ->where('offers.items.0.title', 'Alpha key')
        ->where('offers.items.0.canDeactivate', true));

    $this->get('/seller/offers?q=Beta')->assertInertia(fn (Assert $page) => $page->where('offers.total', 1));
    $this->get('/seller/offers?q='.$a->public_id)->assertInertia(fn (Assert $page) => $page->where('offers.items.0.id', $a->id));
});

it('runs batch deactivate, relist, extend and delete on own offers only, through the state machine', function (): void {
    $one = ($this->own)();
    $two = ($this->own)();
    $foreign = Offer::factory()->create();

    $this->post('/seller/offers/bulk', ['operation' => 'deactivate', 'ids' => [$one->id, $two->id, $foreign->id]])
        ->assertSessionHas('success', __('offer.bulk.result', ['done' => 2, 'failed' => 0]));
    expect($one->refresh()->status)->toBe(OfferStatus::Deactivated)
        ->and($foreign->refresh()->status)->toBe(OfferStatus::Active)
        ->and(OfferStatusChange::query()->where('offer_id', $one->id)->value('to_status'))->toBe(OfferStatus::Deactivated);

    // Relisting a previously approved offer of a non-auto-approve seller goes back to review.
    $this->post('/seller/offers/bulk', ['operation' => 'relist', 'ids' => [$one->id]]);
    expect($one->refresh()->status)->toBe(OfferStatus::PendingReview);

    $live = ($this->own)(['expires_at' => now()->addDays(2)]);
    $this->post('/seller/offers/bulk', ['operation' => 'extend', 'ids' => [$live->id], 'days' => 15]);
    expect($live->refresh()->expires_at?->isSameDay(now()->addDays(17)))->toBeTrue();

    $this->post('/seller/offers/bulk', ['operation' => 'delete', 'ids' => [$two->id, $live->id]]);
    expect(Offer::query()->whereKey([$two->id, $live->id])->count())->toBe(0)
        ->and(Offer::withTrashed()->find($live->id)?->status)->toBe(OfferStatus::Deactivated);
});

it('edits price, sort and stock inline; price edits keep a history and bump the version', function (): void {
    $offer = ($this->own)(['unit_price_amount' => 1000, 'manual_stock_qty' => 5]);

    $this->patch("/seller/offers/{$offer->id}/inline", ['field' => 'price', 'value' => '8.99'])->assertSessionHasNoErrors();
    $this->patch("/seller/offers/{$offer->id}/inline", ['field' => 'price', 'value' => '-1'])->assertSessionHasErrors('offers');
    $this->patch("/seller/offers/{$offer->id}/inline", ['field' => 'sort', 'value' => '7']);
    $this->patch("/seller/offers/{$offer->id}/inline", ['field' => 'stock', 'value' => '0']);

    $offer->refresh();
    expect($offer->unit_price_amount)->toBe(899)
        ->and($offer->version)->toBe(2)
        ->and(OfferPriceHistory::query()->where('offer_id', $offer->id)->pluck('unit_price_amount')->all())->toBe([899])
        ->and($offer->sort_weight)->toBe(7)
        ->and($offer->status)->toBe(OfferStatus::OutOfStock);

    $this->patch("/seller/offers/{$offer->id}/inline", ['field' => 'stock', 'value' => '3']);
    expect($offer->refresh()->status)->toBe(OfferStatus::Active);

    $foreign = Offer::factory()->create();
    $this->patch("/seller/offers/{$foreign->id}/inline", ['field' => 'price', 'value' => '1.00'])->assertNotFound();
});

it('expires listings past their date, idempotently', function (): void {
    $old = ($this->own)(['expires_at' => now()->subMinute()]);
    $fresh = ($this->own)(['expires_at' => now()->addDay()]);

    Artisan::call('offers:expire');
    Artisan::call('offers:expire');

    expect($old->refresh()->status)->toBe(OfferStatus::Expired)
        ->and($fresh->refresh()->status)->toBe(OfferStatus::Active)
        ->and(OfferStatusChange::query()->where('offer_id', $old->id)->count())->toBe(1);

    // Extending an expired offer relists it.
    $this->post('/seller/offers/bulk', ['operation' => 'extend', 'ids' => [$old->id], 'days' => 7]);
    expect($old->refresh()->status)->toBe(OfferStatus::PendingReview);
});
