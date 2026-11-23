<?php

declare(strict_types=1);

use App\Domains\Engagement\Models\BrowsingHistory;
use App\Domains\Engagement\Models\Favorite;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Http\Controllers\EngagementController;
use App\Shared\Support\Settings;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CatalogFixture;

beforeEach(function (): void {
    $this->fx = CatalogFixture::build();
    $this->user = User::factory()->create();
    $this->offer = Offer::factory()->forProduct($this->fx['products']['p3'])->price(450)->create();
});

it('toggles offer and product favorites and lists them on My Favorites', function (): void {
    $this->actingAs($this->user);

    $this->post('/favorites', ['type' => 'offer', 'id' => $this->offer->public_id])->assertSessionHas('success', __('favorites.added'));
    $this->post('/favorites', ['type' => 'product', 'id' => $this->fx['products']['p1']->public_id]);

    $this->get('/buy/favorites')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Buy/Favorites')
        ->has('items', 2)
        ->where('items.0.kind', 'product')
        ->where('items.1.kind', 'offer')
        ->where('items.1.priceCents', 450));

    $this->get('/en/star-quest/video-games/gamma-standard')->assertInertia(fn (Assert $page) => $page->where('offers.0.favorited', true)->where('isFavorite', false));

    $this->post('/favorites', ['type' => 'offer', 'id' => $this->offer->public_id])->assertSessionHas('success', __('favorites.removed'));
    expect(Favorite::query()->count())->toBe(1);
});

it('refuses favorites of hidden offers and guests, but hides vanished favorites quietly', function (): void {
    $hidden = Offer::factory()->status(OfferStatus::Rejected)->create();

    $this->post('/favorites', ['type' => 'offer', 'id' => $this->offer->public_id])->assertRedirect();
    expect(Favorite::query()->count())->toBe(0);

    $this->actingAs($this->user);
    $this->post('/favorites', ['type' => 'offer', 'id' => $hidden->public_id])->assertNotFound();
    $this->post('/favorites', ['type' => 'game', 'id' => $this->offer->public_id])->assertSessionHasErrors('type');

    $this->post('/favorites', ['type' => 'offer', 'id' => $this->offer->public_id]);
    $this->offer->forceFill(['status' => OfferStatus::Deactivated])->save();
    $this->get('/buy/favorites')->assertInertia(fn (Assert $page) => $page->has('items', 0));
});

it('records browsing history for users and guests, newest first, capped at the policy limit', function (): void {
    app(Settings::class)->set('browsing_history_limit', 2);

    $this->actingAs($this->user);
    $this->get('/en/star-quest/video-games/alpha-standard');
    $this->get('/en/star-quest/video-games/beta-deluxe');
    $this->get("/en/offer/{$this->offer->public_id}");
    $this->get('/en/star-quest/video-games/beta-deluxe')->assertInertia(fn (Assert $page) => $page
        ->has('history', 1) // the current product is left out of its own strip
        ->where('history.0.kind', 'offer'));

    expect(BrowsingHistory::query()->where('user_id', $this->user->id)->count())->toBe(2);

    auth()->logout();
    $this->get('/en/star-quest/video-games/alpha-standard')->assertCookie(EngagementController::GUEST_COOKIE);
    expect(BrowsingHistory::query()->whereNull('user_id')->whereNotNull('guest_token')->count())->toBe(1);
});

it('removes one history entry or all of them, only the owner\'s', function (): void {
    $this->actingAs($this->user);
    $this->get('/en/star-quest/video-games/alpha-standard');
    $this->get('/en/star-quest/video-games/beta-deluxe');
    $other = BrowsingHistory::query()->create(['user_id' => User::factory()->create()->id, 'product_id' => $this->fx['products']['p1']->id, 'viewed_at' => now()]);

    $mine = BrowsingHistory::query()->where('user_id', $this->user->id)->orderBy('id')->firstOrFail();
    $this->delete("/history/{$mine->id}")->assertRedirect();
    $this->delete("/history/{$other->id}");
    expect(BrowsingHistory::query()->count())->toBe(2);

    $this->delete('/history')->assertSessionHas('success');
    expect(BrowsingHistory::query()->pluck('id')->all())->toBe([$other->id]);
});
