<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Enums\InsuranceMode;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Models\OfferPriceHistory;
use App\Domains\Offers\Models\OfferStatusChange;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerServiceApplication;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Models\StorageGroup;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OfferFixture;

beforeEach(function (): void {
    $this->fx = OfferFixture::build();
    $this->actingAs($this->fx['seller']);
});

function lastOffer(): Offer
{
    return Offer::query()->where('seller_profile_id', test()->fx['profile']->id)->latest('id')->firstOrFail();
}

it('shows the form with only passed services, and the chosen category\'s products, methods and schema', function (): void {
    $this->get('/seller/offers/create')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Seller/Offers/Form')
        ->has('services', 2)
        ->where('category', null)
        ->where('commissionBps', 800));

    $this->get('/seller/offers/create?category='.$this->fx['accounts']->id)->assertInertia(fn (Assert $page) => $page
        ->where('category.id', $this->fx['accounts']->id)
        ->where('category.methods.0.value', 'account_transfer')
        ->has('category.attributes', 2)
        ->has('category.products', 0));
});

it('sends a new seller\'s offer to review, records price and status history', function (): void {
    $this->post('/seller/offers', OfferFixture::payload($this->fx))->assertRedirect('/seller/offers')->assertSessionHasNoErrors();

    $offer = lastOffer();
    expect($offer->status)->toBe(OfferStatus::PendingReview)
        ->and($offer->unit_price_amount)->toBe(1250)
        ->and($offer->currency)->toBe('USD')
        ->and($offer->product_id)->toBe($this->fx['catalog']['products']['p1']->id)
        ->and($offer->attribute_values)->toBe($this->fx['catalog']['products']['p1']->attribute_values)
        ->and($offer->version)->toBe(1)
        ->and($offer->expires_at)->toBeNull()
        ->and(OfferPriceHistory::query()->where('offer_id', $offer->id)->pluck('unit_price_amount')->all())->toBe([1250])
        ->and(OfferStatusChange::query()->where('offer_id', $offer->id)->get()->map(fn ($c) => [$c->from_status?->value, $c->to_status->value])->all())
        ->toBe([['draft', 'pending_review']]);
});

it('publishes at once for auto-approve levels, starting the listing period', function (): void {
    $this->fx['profile']->forceFill(['seller_level_id' => SellerLevel::query()->where('code', 'silver')->value('id')])->save();

    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['listing_days' => 15]))->assertSessionHasNoErrors();

    $offer = lastOffer();
    expect($offer->status)->toBe(OfferStatus::Active)
        ->and($offer->published_at)->not->toBeNull()
        ->and($offer->expires_at?->isSameDay(now()->addDays(15)))->toBeTrue();
});

it('keeps pending-publish offers off the site until the seller publishes them', function (): void {
    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['publish_mode' => 'pending']))->assertSessionHasNoErrors();
    expect(lastOffer()->status)->toBe(OfferStatus::PendingPublish);

    $this->post('/seller/offers/bulk', ['operation' => 'relist', 'ids' => [lastOffer()->id]])->assertSessionHas('success');
    expect(lastOffer()->status)->toBe(OfferStatus::PendingReview);
});

it('creates a unit offer without product, validating attributes against the schema', function (): void {
    $payload = OfferFixture::payload($this->fx, [
        'game_category_id' => $this->fx['accounts']->id,
        'product_id' => null,
        'title' => 'EU account, level 80',
        'delivery_method' => 'account_transfer',
        'attribute_values' => ['server' => 'eu', 'level' => '80'],
        'insurance_days' => 7,
    ]);

    $this->post('/seller/offers', $payload)->assertSessionHasNoErrors();

    $offer = lastOffer();
    expect($offer->attribute_values)->toBe(['server' => 'eu', 'level' => 80])
        ->and($offer->insurance_mode)->toBe(InsuranceMode::Optional)
        ->and($offer->account_recovery_protection_days)->toBe(30);

    $this->post('/seller/offers', [...$payload, 'attribute_values' => ['level' => '80']])->assertSessionHasErrors('attribute_values');
    $this->post('/seller/offers', [...$payload, 'attribute_values' => ['server' => 'mars']])->assertSessionHasErrors('attribute_values');
});

it('flags a duplicate (same product, method and attributes) and always sends it to review', function (): void {
    $this->fx['profile']->forceFill(['seller_level_id' => SellerLevel::query()->where('code', 'gold')->value('id')])->save();

    $this->post('/seller/offers', OfferFixture::payload($this->fx));
    $first = lastOffer();
    expect($first->status)->toBe(OfferStatus::Active)->and($first->duplicate_of_offer_id)->toBeNull();

    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['title' => 'Same thing again', 'unit_price' => '11.00']))
        ->assertSessionHas('success', __('offer.saved.duplicate'));
    $second = lastOffer();
    expect($second->duplicate_of_offer_id)->toBe($first->id)->and($second->status)->toBe(OfferStatus::PendingReview);

    // A different delivery method is a legitimate second offer.
    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['delivery_method' => 'account_transfer']));
    expect(lastOffer()->duplicate_of_offer_id)->toBeNull()->and(lastOffer()->status)->toBe(OfferStatus::Active);
});

it('refuses what the catalog, the service applications or the seller do not allow', function (array $overrides, string $field): void {
    $this->post('/seller/offers', OfferFixture::payload($this->fx, $overrides))->assertSessionHasErrors($field);
    expect(Offer::query()->where('seller_profile_id', $this->fx['profile']->id)->exists())->toBeFalse();
})->with([
    'contact info in title' => [['title' => 'Cheap keys, telegram: @fastkeys'], 'title'],
    'email in description' => [['description' => ['en' => 'Mail me: deals@example.com']], 'description.en'],
    'zero price' => [['unit_price' => '0'], 'unit_price'],
    'float-ish price' => [['unit_price' => '12.505'], 'unit_price'],
    'method not allowed here' => [['delivery_method' => 'service'], 'delivery_method'],
    'max below min' => [['min_qty' => 5, 'max_qty' => 2], 'max_qty'],
    'bad delivery time' => [['delivery_time_minutes' => 37], 'delivery_time_minutes'],
    'manual without stock' => [['manual_stock_qty' => null], 'manual_stock_qty'],
    'terms not accepted' => [['terms' => false], 'terms'],
    'tier not cheaper' => [['quantity_tiers' => [['min_qty' => 10, 'unit_price' => '13.00']]], 'quantity_tiers'],
    'product of another category' => [['product_id' => 999999], 'product_id'],
]);

it('requires a passed service application for the game×category', function (): void {
    $this->fx['profile']->serviceApplications()->where('game_category_id', $this->fx['accounts']->id)->delete();

    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['game_category_id' => $this->fx['accounts']->id, 'product_id' => null, 'delivery_method' => 'account_transfer', 'attribute_values' => ['server' => 'eu']]))
        ->assertSessionHasErrors('game_category_id');
});

it('enforces compulsory insurance from the game×category policy', function (): void {
    $this->fx['catalog']['gameCategory']->forceFill(['policy_override' => ['compulsory_insurance_days' => 7]])->save();

    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['insurance_days' => 3]))->assertSessionHasErrors('insurance_days');
    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['insurance_days' => 15]))->assertSessionHasNoErrors();
    expect(lastOffer()->insurance_mode)->toBe(InsuranceMode::Compulsory);
});

it('links auto delivery to a storage group of a matching type only', function (): void {
    $keys = StorageGroup::query()->create(['seller_profile_id' => $this->fx['profile']->id, 'name' => 'Alpha keys', 'item_type' => StorageItemType::Key, 'low_stock_threshold' => 5]);
    $accounts = StorageGroup::query()->create(['seller_profile_id' => $this->fx['profile']->id, 'name' => 'Accounts', 'item_type' => StorageItemType::Account, 'low_stock_threshold' => 5]);

    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['fulfillment' => 'auto', 'storage_group_id' => $accounts->id, 'manual_stock_qty' => null]))->assertSessionHasErrors('storage_group_id');
    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['fulfillment' => 'auto', 'delivery_method' => 'send_gift', 'storage_group_id' => $keys->id, 'manual_stock_qty' => null]))->assertSessionHasErrors('fulfillment');

    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['fulfillment' => 'auto', 'storage_group_id' => $keys->id, 'manual_stock_qty' => null]))->assertSessionHasNoErrors();
    expect(lastOffer()->fulfillment)->toBe(Fulfillment::Auto)
        ->and(lastOffer()->storage_group_id)->toBe($keys->id)
        ->and(lastOffer()->manual_stock_qty)->toBeNull();
});

it('caps listed offers at the seller level limit', function (): void {
    SellerLevel::query()->where('code', 'new')->update(['max_active_offers' => 1]);

    $this->post('/seller/offers', OfferFixture::payload($this->fx))->assertSessionHasNoErrors();
    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['product_id' => $this->fx['catalog']['products']['p2']->id]))->assertSessionHasErrors('offer');
    // Pending publish does not count until published.
    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['product_id' => $this->fx['catalog']['products']['p2']->id, 'publish_mode' => 'pending']))->assertSessionHasNoErrors();
});

it('edits: price change bumps the version, content change returns a listed offer to review, identity fields are fixed', function (): void {
    $offer = Offer::factory()->forProduct($this->fx['catalog']['products']['p1'])->create(['seller_profile_id' => $this->fx['profile']->id, 'attributes_hash' => 'x', 'attribute_values' => $this->fx['catalog']['products']['p1']->attribute_values]);
    $base = OfferFixture::payload($this->fx, ['title' => $offer->title, 'description' => ['en' => $offer->description['en'] ?? ''], 'unit_price' => '10.00', 'manual_stock_qty' => 50, 'delivery_time_minutes' => 60]);

    $this->get("/seller/offers/{$offer->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page->where('offer.unit_price', '10.00'));

    $this->put("/seller/offers/{$offer->id}", [...$base, 'unit_price' => '9.40'])->assertSessionHasNoErrors();
    $offer->refresh();
    expect($offer->unit_price_amount)->toBe(940)->and($offer->version)->toBe(2)->and($offer->status)->toBe(OfferStatus::Active);

    $this->put("/seller/offers/{$offer->id}", [...$base, 'unit_price' => '9.40', 'title' => 'New wording'])->assertSessionHasNoErrors();
    expect($offer->refresh()->status)->toBe(OfferStatus::PendingReview);

    $this->put("/seller/offers/{$offer->id}", [...$base, 'delivery_method' => 'account_transfer'])->assertSessionHasErrors('offer');
});

it('hides other sellers\' offers and refuses writes from suspended stores', function (): void {
    $foreign = Offer::factory()->create();
    $this->get("/seller/offers/{$foreign->id}/edit")->assertNotFound();
    $this->put("/seller/offers/{$foreign->id}", OfferFixture::payload($this->fx))->assertNotFound();

    $this->fx['profile']->forceFill(['status' => SellerStatus::Suspended])->save();
    $this->post('/seller/offers', OfferFixture::payload($this->fx))->assertSessionHasErrors('offer');
});

it('gives each approved service its category type and game name for the category-first picker', function (): void {
    $this->get('/seller/offers/create')->assertInertia(fn (Assert $page) => $page
        ->has('services', 2)
        ->where('services', fn ($services): bool => collect($services)->pluck('typeCode')->sort()->values()->all() === ['accounts', 'video_games']
            && collect($services)->every(fn (array $s): bool => $s['game'] === $this->fx['catalog']['game']->translate('name', 'en'))));
});

it('lists the games the seller can still apply for on the form, and applying passes instantly for auto-approve levels', function (): void {
    $other = GameCategory::factory()->ofType(CategoryTypeCode::Accounts)->create();
    $pending = GameCategory::factory()->ofType(CategoryTypeCode::Accounts)->create();
    SellerServiceApplication::query()->create(['seller_profile_id' => $this->fx['profile']->id, 'game_category_id' => $pending->id]);

    $this->get('/seller/offers/create')->assertInertia(fn (Assert $page) => $page
        ->where('applicable', fn ($rows): bool => collect($rows)->pluck('id')->doesntContain($this->fx['accounts']->id)
            && collect($rows)->firstWhere('id', $other->id)['pending'] === false
            && collect($rows)->firstWhere('id', $pending->id)['pending'] === true));

    $this->fx['profile']->forceFill(['seller_level_id' => SellerLevel::query()->where('code', 'silver')->value('id')])->save();
    $this->from('/seller/offers/create')->post('/seller/services', ['game_category_id' => $other->id])->assertRedirect('/seller/offers/create');

    $this->get('/seller/offers/create?category='.$other->id)->assertInertia(fn (Assert $page) => $page
        ->where('category.id', $other->id)
        ->where('applicable', fn ($rows): bool => collect($rows)->pluck('id')->doesntContain($other->id)));
});
