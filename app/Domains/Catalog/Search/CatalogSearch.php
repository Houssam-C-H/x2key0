<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Search;

use App\Domains\Catalog\Models\Product;

/**
 * Listing search with disjunctive facet counts (spec 1.4.1 / 4.1).
 * Drivers: DatabaseCatalogSearch (reference, exact) and MeilisearchCatalogSearch (production scale).
 */
interface CatalogSearch
{
    public function listing(ListingQuery $query): ListingResult;

    /** Keeps the search index in sync after a product change (no-op for the database driver). */
    public function index(Product $product): void;

    public function remove(Product $product): void;
}
