<?php

declare(strict_types=1);

use App\Domains\Catalog\Search\DatabaseCatalogSearch;
use App\Domains\Catalog\Search\ListingQuery;
use App\Domains\Catalog\Search\MeilisearchCatalogSearch;
use Meilisearch\Client;
use Tests\Support\CatalogFixture;

/**
 * Both drivers must return identical results and facet counts (spec M3 acceptance: "facet counts correct").
 * Runs when a Meilisearch server is reachable (CI service, or local: C:\dev\tools\meilisearch). Skipped otherwise.
 */
beforeEach(function (): void {
    $host = (string) (getenv('MEILISEARCH_HOST') ?: 'http://127.0.0.1:7700');
    $key = getenv('MEILISEARCH_KEY') ?: null;
    $client = new Client($host, $key ?: null);

    try {
        $client->health();
    } catch (Throwable) {
        $this->markTestSkipped("Meilisearch not reachable at {$host}");
    }

    $this->fx = CatalogFixture::build();
    $this->meili = (new MeilisearchCatalogSearch($client, 'x2key_test_products_'.getmypid()))->waitForTasks();
    $this->meili->reindex();
    $this->db = new DatabaseCatalogSearch;
});

afterEach(function (): void {
    if (isset($this->meili)) {
        (new Client((string) (getenv('MEILISEARCH_HOST') ?: 'http://127.0.0.1:7700'), getenv('MEILISEARCH_KEY') ?: null))
            ->deleteIndex('x2key_test_products_'.getmypid());
    }
});

it('returns the same ids, totals, facets and bounds as the database driver', function (array $args): void {
    $query = new ListingQuery(...['gameCategoryId' => $this->fx['gameCategory']->id, ...$args]);

    $db = $this->db->listing($query);
    $meili = $this->meili->listing($query);

    expect(array_map(fn ($p) => $p->id, $meili->items))->toBe(array_map(fn ($p) => $p->id, $db->items))
        ->and($meili->total)->toBe($db->total)
        ->and($meili->facets)->toBe($db->facets)
        ->and($meili->bounds)->toBe($db->bounds);
})->with([
    'no filters' => [[]],
    'one facet' => [['facets' => ['attr.edition' => ['standard']]]],
    'OR + AND' => [['facets' => ['attr.edition' => ['standard', 'deluxe'], 'platform' => ['ps']]]],
    'multiselect' => [['facets' => ['attr.genres' => ['rpg']]]],
    'boolean' => [['facets' => ['attr.multiplayer' => ['no']]]],
    'range' => [['ranges' => ['release_year' => ['min' => 2021.0, 'max' => null]]]],
    'price' => [['priceMin' => 600, 'priceMax' => 2000]],
    'price sort' => [['sort' => 'price_desc']],
    'name sort' => [['sort' => 'name']],
    'recommended' => [['sort' => 'recommended']],
]);
