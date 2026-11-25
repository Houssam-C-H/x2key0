<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\ServiceType;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Models\SellerLevel;
use App\Filament\Resources\Catalog\CategoryTypes\Pages\EditCategoryType;
use App\Filament\Resources\Catalog\CategoryTypes\RelationManagers\ServiceTypesRelationManager;
use Database\Factories\GameCategoryFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Support\OfferFixture;

/*
 * Service types (ADR-032): staff-managed kinds of service inside a category type. Sellers must pick an enabled one
 * when the category type has any; switching one off stops listings and hides its offers.
 */

beforeEach(function (): void {
    $this->fx = OfferFixture::build();
    // Auto-approve so offers go live at once and show on the storefront.
    $this->fx['profile']->forceFill(['seller_level_id' => SellerLevel::query()->where('code', 'silver')->value('id')])->save();
    $this->actingAs($this->fx['seller']);

    $this->smurf = ServiceType::factory()->ofType(CategoryTypeCode::Accounts)->create(['code' => 'smurf', 'name' => ['en' => 'Smurf'], 'sort' => 1]);
    $this->ranked = ServiceType::factory()->ofType(CategoryTypeCode::Accounts)->create(['code' => 'ranked', 'name' => ['en' => 'Ranked-ready'], 'sort' => 2]);

    $this->accountsPayload = fn (array $overrides = []): array => OfferFixture::payload($this->fx, [
        'game_category_id' => $this->fx['accounts']->id,
        'product_id' => null,
        'delivery_method' => 'account_transfer',
        'attribute_values' => ['server' => 'eu'],
        ...$overrides,
    ]);
});

function latestOffer(): Offer
{
    return Offer::query()->where('seller_profile_id', test()->fx['profile']->id)->latest('id')->firstOrFail();
}

it('offers the enabled service types of the chosen category on the form, in staff order', function (): void {
    ServiceType::factory()->ofType(CategoryTypeCode::Accounts)->off()->create(['code' => 'hidden', 'name' => ['en' => 'Hidden']]);

    $this->get('/seller/offers/create?category='.$this->fx['accounts']->id)->assertInertia(fn (Assert $page) => $page
        ->has('category.services', 2)
        ->where('category.services.0.name', 'Smurf')
        ->where('category.services.1.name', 'Ranked-ready'));

    // A category type without service types asks for none.
    $this->get('/seller/offers/create?category='.$this->fx['catalog']['gameCategory']->id)->assertInertia(fn (Assert $page) => $page->has('category.services', 0));
});

it('requires an enabled service type of the category, and saves it', function (): void {
    $this->post('/seller/offers', ($this->accountsPayload)())->assertSessionHasErrors('service_type_id');

    $this->ranked->update(['is_enabled' => false]);
    $this->post('/seller/offers', ($this->accountsPayload)(['service_type_id' => $this->ranked->id]))->assertSessionHasErrors('service_type_id');

    $boosting = ServiceType::factory()->ofType(CategoryTypeCode::Boosting)->create();
    $this->post('/seller/offers', ($this->accountsPayload)(['service_type_id' => $boosting->id]))->assertSessionHasErrors('service_type_id');

    expect(Offer::query()->where('seller_profile_id', $this->fx['profile']->id)->count())->toBe(0);

    $this->post('/seller/offers', ($this->accountsPayload)(['service_type_id' => $this->smurf->id]))->assertSessionHasNoErrors();
    expect(latestOffer()->service_type_id)->toBe($this->smurf->id)
        ->and(latestOffer()->status)->toBe(OfferStatus::Active);
});

it('rejects a service type in a category type that has none', function (): void {
    $this->post('/seller/offers', OfferFixture::payload($this->fx, ['service_type_id' => $this->smurf->id]))->assertSessionHasErrors('service_type_id');
    $this->post('/seller/offers', OfferFixture::payload($this->fx))->assertSessionHasNoErrors();

    expect(latestOffer()->service_type_id)->toBeNull();
});

it('does not flag offers of different service types as duplicates', function (): void {
    $this->post('/seller/offers', ($this->accountsPayload)(['service_type_id' => $this->smurf->id]))->assertSessionHasNoErrors();
    $this->post('/seller/offers', ($this->accountsPayload)(['service_type_id' => $this->ranked->id]))->assertSessionHasNoErrors();
    expect(latestOffer()->duplicate_of_offer_id)->toBeNull();

    $this->post('/seller/offers', ($this->accountsPayload)(['service_type_id' => $this->ranked->id]))->assertSessionHasNoErrors();
    expect(latestOffer()->duplicate_of_offer_id)->not->toBeNull();
});

it('lets the seller move an offer to another service, but not keep one that was switched off', function (): void {
    $this->post('/seller/offers', ($this->accountsPayload)(['service_type_id' => $this->smurf->id]))->assertSessionHasNoErrors();
    $offer = latestOffer();

    $this->put('/seller/offers/'.$offer->id, ($this->accountsPayload)(['service_type_id' => $this->ranked->id]))->assertSessionHasNoErrors();
    expect($offer->refresh()->service_type_id)->toBe($this->ranked->id);

    $this->ranked->update(['is_enabled' => false]);
    $this->put('/seller/offers/'.$offer->id, ($this->accountsPayload)(['service_type_id' => $this->ranked->id]))->assertSessionHasErrors('service_type_id');
});

it('hides offers of a switched-off service from the storefront until it is switched back on', function (): void {
    $this->post('/seller/offers', ($this->accountsPayload)(['service_type_id' => $this->smurf->id, 'title' => 'Smurf account EU']))->assertSessionHasNoErrors();
    $offer = latestOffer();
    $listing = $this->fx['accounts']->path('en');

    $this->get($listing)->assertInertia(fn (Assert $page) => $page->where('offerResults.total', 1)->where('offerResults.items.0.serviceType', 'Smurf'));
    expect(Offer::query()->live()->whereKey($offer->id)->exists())->toBeTrue();

    $this->smurf->update(['is_enabled' => false]);

    $this->get($listing)->assertInertia(fn (Assert $page) => $page->where('offerResults.total', 0)->has('serviceTypes', 1));
    expect(Offer::query()->live()->whereKey($offer->id)->exists())->toBeFalse();
    auth()->logout();
    $this->get($offer->path('en'))->assertNotFound();

    $this->smurf->update(['is_enabled' => true]);
    expect(Offer::query()->live()->whereKey($offer->id)->exists())->toBeTrue();
});

it('shows service chips with live counts and filters the listing by ?service=', function (): void {
    $this->post('/seller/offers', ($this->accountsPayload)(['service_type_id' => $this->smurf->id, 'title' => 'Smurf one']))->assertSessionHasNoErrors();
    $this->post('/seller/offers', ($this->accountsPayload)(['service_type_id' => $this->ranked->id, 'title' => 'Ranked one', 'attribute_values' => ['server' => 'na']]))->assertSessionHasNoErrors();
    $listing = $this->fx['accounts']->path('en');

    $this->get($listing)->assertInertia(fn (Assert $page) => $page
        ->where('serviceTypes', [['code' => 'smurf', 'name' => 'Smurf', 'count' => 1], ['code' => 'ranked', 'name' => 'Ranked-ready', 'count' => 1]])
        ->where('activeService', null)
        ->where('offerResults.total', 2));

    $this->get($listing.'?service=ranked')->assertInertia(fn (Assert $page) => $page
        ->where('activeService', 'ranked')
        ->where('offerResults.total', 1)
        ->where('offerResults.items.0.title', 'Ranked one'));

    // Unknown codes are ignored.
    $this->get($listing.'?service=nope')->assertInertia(fn (Assert $page) => $page->where('activeService', null)->where('offerResults.total', 2));
});

it('lets catalog staff manage service types on the category type screen', function (): void {
    $moderator = User::factory()->staff(StaffRole::Moderator)->withTwoFactor()->create();
    $type = GameCategoryFactory::type(CategoryTypeCode::Accounts);

    $this->actingAs($moderator)->get('/admin/catalog/category-types/'.$type->id.'/edit')->assertOk();

    Livewire::test(ServiceTypesRelationManager::class, ['ownerRecord' => $type, 'pageClass' => EditCategoryType::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$this->smurf, $this->ranked]);
});
