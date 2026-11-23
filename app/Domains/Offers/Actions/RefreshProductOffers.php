<?php

declare(strict_types=1);

namespace App\Domains\Offers\Actions;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Search\CatalogSearch;
use App\Domains\Offers\Models\Offer;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes a product's cached lowest price and live offer count from its offers (spec 5.2 caches feed
 * listing cards, price filters and sorts), then re-indexes it. Idempotent; called after every change that
 * can affect a live offer, and for all products by `catalog:recount`.
 */
final class RefreshProductOffers
{
    public function __construct(private readonly CatalogSearch $search) {}

    public function handle(?int $productId): void
    {
        if ($productId === null) {
            return;
        }

        DB::afterCommit(function () use ($productId): void {
            $product = Product::query()->find($productId);

            if ($product === null || ! $this->apply($product)) {
                return;
            }

            $this->search->index($product);
        });
    }

    /**
     * Writes the caches without events (no audit row, no attribute re-validation). Returns true if they changed.
     */
    public function apply(Product $product): bool
    {
        $live = Offer::query()->live()->where('offers.product_id', $product->id)->where('offers.currency', 'USD');

        $count = (clone $live)->count();
        $lowest = $count === 0 ? null : (clone $live)->min('offers.unit_price_amount');
        $lowest = is_numeric($lowest) ? (int) $lowest : null;

        if ($product->offer_count_cached === $count && $product->lowest_price_amount_cached === $lowest) {
            return false;
        }

        $product->forceFill(['offer_count_cached' => $count, 'lowest_price_amount_cached' => $lowest])->saveQuietly();

        return true;
    }
}
