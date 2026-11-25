<?php

declare(strict_types=1);

use App\Domains\Sellers\Models\SellerLevel;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OfferFixture;

/*
 * Offer-only categories (accounts, boosting…) get the same filters as product listings, on the offers' attributes.
 */

beforeEach(function (): void {
    $this->fx = OfferFixture::build();
    $this->fx['profile']->forceFill(['seller_level_id' => SellerLevel::query()->where('code', 'silver')->value('id')])->save();
    $this->actingAs($this->fx['seller']);

    foreach ([['EU low', 'eu', 10, '5.00'], ['EU high', 'eu', 80, '25.00'], ['NA mid', 'na', 40, '15.00']] as [$title, $server, $level, $price]) {
        $this->post('/seller/offers', OfferFixture::payload($this->fx, [
            'game_category_id' => $this->fx['accounts']->id, 'product_id' => null, 'delivery_method' => 'account_transfer',
            'title' => $title, 'attribute_values' => ['server' => $server, 'level' => $level], 'unit_price' => $price,
        ]))->assertSessionHasNoErrors();
    }
    $this->url = $this->fx['accounts']->path('en');
});

it('counts facet options from the live offers', function (): void {
    $this->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('offerResults.total', 3)
        ->where('filters', fn ($filters): bool => collect(collect($filters)->firstWhere('key', 'attr.server')['options'])->pluck('count', 'value')->all() === ['eu' => 2, 'na' => 1]
            && collect($filters)->firstWhere('key', 'level')['bounds'] == ['min' => 10, 'max' => 80]));
});

it('filters offers by facet, number range, price and title, and sorts them', function (): void {
    $titles = fn (string $qs) => collect($this->get($this->url.'?'.$qs)->viewData('page')['props']['offerResults']['items'])->pluck('title')->all();

    expect($titles('f[server][]=eu&sort=price_asc'))->toBe(['EU low', 'EU high'])
        ->and($titles('f[server][]=eu&sort=price_desc'))->toBe(['EU high', 'EU low'])
        ->and($titles('r[level][min]=30'))->toEqualCanonicalizing(['EU high', 'NA mid'])
        ->and($titles('price[max]=16'))->toEqualCanonicalizing(['EU low', 'NA mid'])
        ->and($titles('q=high'))->toBe(['EU high']);
});
