<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Search\CatalogSearch;
use App\Domains\Catalog\Search\DatabaseCatalogSearch;
use App\Domains\Catalog\Search\ListingQuery;
use Tests\Support\CatalogFixture;

beforeEach(function (): void {
    $this->fx = CatalogFixture::build();
    $this->search = new DatabaseCatalogSearch;
    $this->q = fn (array $args = []): ListingQuery => new ListingQuery(...['gameCategoryId' => $this->fx['gameCategory']->id, ...$args]);
    $this->names = fn ($result): array => array_map(fn (Product $p): string => $p->name['en'], $result->items);
});

it('lists active products only, with every facet counted', function (): void {
    $r = $this->search->listing(($this->q)());

    expect($r->total)->toBe(4)
        ->and($r->facets['attr.edition'])->toBe(['deluxe' => 1, 'standard' => 2, 'ultimate' => 1])
        ->and($r->facets['attr.genres'])->toBe(['action' => 2, 'rpg' => 2, 'shooter' => 1])
        ->and($r->facets['attr.multiplayer'])->toBe(['no' => 1, 'yes' => 3])
        ->and($r->facets['platform'])->toBe(['pc' => 2, 'ps' => 2])
        ->and($r->facets['region'])->toBe(['EU' => 2, 'US' => 2])
        ->and($r->bounds['release_year'])->toBe(['min' => 2019.0, 'max' => 2024.0])
        ->and($r->facets)->not->toHaveKey('attr.notes');
});

it('counts facets disjunctively: a group ignores its own selection, applies the others', function (): void {
    $r = $this->search->listing(($this->q)(['facets' => ['attr.edition' => ['standard']]]));

    expect(($this->names)($r))->toEqualCanonicalizing(['Alpha Standard', 'Gamma Standard'])
        // Own group: still shows every edition so the buyer can add more.
        ->and($r->facets['attr.edition'])->toBe(['deluxe' => 1, 'standard' => 2, 'ultimate' => 1])
        // Other groups: narrowed to standard editions (P1, P3).
        ->and($r->facets['platform'])->toBe(['pc' => 2])
        ->and($r->facets['attr.genres'])->toBe(['action' => 1, 'rpg' => 1])
        ->and($r->facets['attr.multiplayer'])->toBe(['yes' => 2]);
});

it('ORs values inside a group and ANDs across groups', function (): void {
    $r = $this->search->listing(($this->q)(['facets' => ['attr.edition' => ['standard', 'deluxe'], 'platform' => ['ps']]]));

    expect(($this->names)($r))->toBe(['Beta Deluxe'])
        ->and($r->facets['attr.edition'])->toBe(['deluxe' => 1, 'ultimate' => 1])   // platform=ps applied: P2, P4
        ->and($r->facets['platform'])->toBe(['pc' => 2, 'ps' => 1]);               // edition std|deluxe applied: P1, P2, P3
});

it('matches multiselect values (a product with several genres counts in each)', function (): void {
    $r = $this->search->listing(($this->q)(['facets' => ['attr.genres' => ['rpg']]]));

    expect(($this->names)($r))->toEqualCanonicalizing(['Beta Deluxe', 'Gamma Standard'])
        ->and($r->facets['attr.edition'])->toBe(['deluxe' => 1, 'standard' => 1]);
});

it('filters booleans with yes/no', function (): void {
    $r = $this->search->listing(($this->q)(['facets' => ['attr.multiplayer' => ['no']]]));

    expect(($this->names)($r))->toBe(['Beta Deluxe']);
});

it('filters number ranges and price ranges (unpriced products drop out of price filters)', function (): void {
    expect(($this->names)($this->search->listing(($this->q)(['ranges' => ['release_year' => ['min' => 2021.0, 'max' => null]]]))))
        ->toEqualCanonicalizing(['Beta Deluxe', 'Gamma Standard']);

    expect(($this->names)($this->search->listing(($this->q)(['priceMax' => 1500]))))
        ->toEqualCanonicalizing(['Alpha Standard', 'Delta Ultimate']);

    expect(($this->names)($this->search->listing(($this->q)(['priceMin' => 600, 'priceMax' => 2000]))))
        ->toEqualCanonicalizing(['Alpha Standard', 'Beta Deluxe']);
});

it('searches product names case-insensitively within the game category', function (): void {
    expect(($this->names)($this->search->listing(($this->q)(['text' => 'STANDARD']))))
        ->toEqualCanonicalizing(['Alpha Standard', 'Gamma Standard']);
});

it('sorts; unpriced products always last on price sorts', function (string $sort, array $expected): void {
    expect(($this->names)($this->search->listing(($this->q)(['sort' => $sort]))))->toBe($expected);
})->with([
    'price asc' => ['price_asc', ['Delta Ultimate', 'Alpha Standard', 'Beta Deluxe', 'Gamma Standard']],
    'price desc' => ['price_desc', ['Beta Deluxe', 'Alpha Standard', 'Delta Ultimate', 'Gamma Standard']],
    'name' => ['name', ['Alpha Standard', 'Beta Deluxe', 'Delta Ultimate', 'Gamma Standard']],
    'recommended' => ['recommended', ['Delta Ultimate', 'Alpha Standard', 'Beta Deluxe', 'Gamma Standard']],
]);

it('paginates 24 per page', function (): void {
    Product::factory()->count(26)->for($this->fx['gameCategory'])->create();

    $page1 = $this->search->listing(($this->q)());
    $page2 = $this->search->listing(($this->q)(['page' => 2]));

    expect($page1->total)->toBe(30)
        ->and($page1->items)->toHaveCount(24)
        ->and($page2->items)->toHaveCount(6)
        ->and($page1->lastPage())->toBe(2)
        ->and(array_intersect(array_map(fn ($p) => $p->id, $page1->items), array_map(fn ($p) => $p->id, $page2->items)))->toBe([]);
});

it('is the default driver', function (): void {
    expect(app(CatalogSearch::class))->toBeInstanceOf(DatabaseCatalogSearch::class);
});
