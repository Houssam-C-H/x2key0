<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\RecountCatalog;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OfferFixture;

beforeEach(function (): void {
    $this->fx = OfferFixture::build();
    $this->p3 = $this->fx['catalog']['products']['p3']; // no offers in the catalog fixture
    $this->path = '/en/star-quest/video-games/gamma-standard';
});

it('shows the offers table and buy box on the product page, recommended order (insured first, then price)', function (): void {
    $cheap = Offer::factory()->forProduct($this->p3)->price(900)->create();
    $insured = Offer::factory()->forProduct($this->p3)->price(1100)->create(['insurance_days' => 7, 'delivery_method' => 'account_transfer']);
    Offer::factory()->forProduct($this->p3)->price(500)->status(OfferStatus::Deactivated)->create();
    Offer::factory()->forProduct($this->p3)->price(400)->create(['seller_profile_id' => SellerProfile::factory()->status(SellerStatus::Suspended)]);

    $this->get($this->path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Public/Catalog/Product')
        ->has('offers', 2)
        ->where('offers.0.id', $insured->public_id)
        ->where('offers.0.insuranceDays', 7)
        ->where('offers.1.id', $cheap->public_id)
        ->where('offers.1.priceCents', 900)
        ->where('offers.1.stockLabel', 'adequate')
        ->where('deliveryMethods', ['account_transfer', 'digital_key'])
        ->where('seo.jsonLd.1.offers.lowPrice', '9.00')
        ->where('seo.jsonLd.1.offers.offerCount', 2));
});

it('shows a single offer page; non-public offers are 404 except as an owner preview', function (): void {
    $offer = Offer::factory()->forProduct($this->p3)->create(['seller_profile_id' => $this->fx['profile']->id, 'description' => ['en' => 'Fast and safe.', 'fr' => 'Rapide.']]);

    $this->get("/fr/offer/{$offer->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Public/Catalog/Offer')
        ->where('offer.description', 'Rapide.')
        ->where('offer.product.name', 'Gamma Standard')
        ->where('preview', null)
        ->where('seo.noindex', false));

    $offer->forceFill(['status' => OfferStatus::PendingReview])->save();
    $this->get("/en/offer/{$offer->public_id}")->assertNotFound();
    $this->actingAs($this->fx['seller'])->get("/en/offer/{$offer->public_id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('preview', 'pending_review')->where('seo.noindex', true));
    $this->get('/en/offer/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
});

it('lists offer cards on game×categories without catalog products', function (): void {
    $accounts = $this->fx['accounts'];
    Offer::factory()->count(2)->create(['game_category_id' => $accounts->id, 'product_id' => null, 'delivery_method' => 'account_transfer']);
    Offer::factory()->create(['game_category_id' => $accounts->id, 'status' => OfferStatus::Rejected]);

    $this->get('/en/star-quest/accounts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Public/Catalog/Listing')
        ->where('offerResults.total', 2)
        ->has('offerResults.items', 2));

    // Product listings keep their product grid.
    $this->get('/en/star-quest/video-games')->assertInertia(fn (Assert $page) => $page->where('offerResults', null));
});

it('keeps product caches and category counts in line with live offers', function (): void {
    $offer = Offer::factory()->forProduct($this->p3)->price(1500)->create();
    app(RecountCatalog::class)->handle();

    expect($this->p3->refresh()->lowest_price_amount_cached)->toBe(1500)
        ->and($this->p3->offer_count_cached)->toBe(1)
        ->and($this->fx['catalog']['gameCategory']->refresh()->offer_count_cached)->toBe(7); // 3 priced products × 2 + this one

    // A suspended store's offers drop out at the next recount.
    $offer->seller->forceFill(['status' => SellerStatus::Suspended])->save();
    app(RecountCatalog::class)->handle();
    expect($this->p3->refresh()->lowest_price_amount_cached)->toBeNull()->and($this->p3->offer_count_cached)->toBe(0);
});

it('refreshes the product price as soon as an offer is published or repriced', function (): void {
    $this->fx['profile']->forceFill(['seller_level_id' => SellerLevel::query()->where('code', 'gold')->value('id')])->save();
    $this->actingAs($this->fx['seller']);

    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['product_id' => $this->p3->id, 'unit_price' => '6.00']))->assertSessionHasNoErrors();
    expect($this->p3->refresh()->lowest_price_amount_cached)->toBe(600);

    $offer = Offer::query()->where('product_id', $this->p3->id)->sole();
    $this->patch("/seller/offers/{$offer->id}/inline", ['field' => 'price', 'value' => '5.25']);
    expect($this->p3->refresh()->lowest_price_amount_cached)->toBe(525);

    $this->post('/seller/offers/bulk', ['operation' => 'deactivate', 'ids' => [$offer->id]]);
    expect($this->p3->refresh()->lowest_price_amount_cached)->toBeNull();
});

it('shows featured and live offers on the public store page', function (): void {
    $featured = Offer::factory()->forProduct($this->p3)->create(['seller_profile_id' => $this->fx['profile']->id]);
    $other = Offer::factory()->forProduct($this->p3)->create(['seller_profile_id' => $this->fx['profile']->id, 'sort_weight' => 5]);
    Offer::factory()->forProduct($this->p3)->status(OfferStatus::Deactivated)->create(['seller_profile_id' => $this->fx['profile']->id]);
    $this->fx['profile']->media?->forceFill(['featured_offer_ids' => [$featured->id]])->save();

    $this->get('/en/store/pixel-vault')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('featured', 1)
        ->where('featured.0.id', $featured->public_id)
        ->has('offers', 1)
        ->where('offers.0.id', $other->public_id));
});

it('lets the seller pick featured offers on My Store: own listed offers only, at most featured_offers_max', function (): void {
    $this->actingAs($this->fx['seller']);
    $own = Offer::factory()->count(5)->create(['seller_profile_id' => $this->fx['profile']->id]);
    $deactivated = Offer::factory()->status(OfferStatus::Deactivated)->create(['seller_profile_id' => $this->fx['profile']->id]);
    $foreign = Offer::factory()->create();

    $this->get('/seller/store')->assertInertia(fn (Assert $page) => $page->has('featuredOptions', 5)->where('featured', []));

    $this->put('/seller/store/featured', ['offer_ids' => $own->pluck('id')->all()])->assertSessionHasErrors('featured');
    $this->put('/seller/store/featured', ['offer_ids' => [$own[2]->id, $deactivated->id, $foreign->id, $own[0]->id]])->assertSessionHasNoErrors();

    expect($this->fx['profile']->media()->first()?->featured_offer_ids)->toBe([$own[2]->id, $own[0]->id]);
    $this->get('/en/store/pixel-vault')->assertInertia(fn (Assert $page) => $page
        ->where('featured.0.id', $own[2]->public_id)
        ->where('featured.1.id', $own[0]->public_id)
        ->has('offers', 3));
});
