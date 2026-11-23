<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Listeners;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Search\CatalogSearch;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the listing index in sync with products, after the surrounding transaction commits.
 * Count caches are refreshed by `catalog:recount` (scheduled) since they span several tables.
 */
final class SyncProductSearch
{
    public function __construct(private readonly CatalogSearch $search) {}

    public function saved(Product $product): void
    {
        DB::afterCommit(fn () => $this->search->index($product));
    }

    public function deleted(Product $product): void
    {
        DB::afterCommit(fn () => $this->search->remove($product));
    }

    public function restored(Product $product): void
    {
        DB::afterCommit(fn () => $this->search->index($product));
    }
}
