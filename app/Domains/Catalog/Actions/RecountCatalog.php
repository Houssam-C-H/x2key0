<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\ProductStatus;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Search\CatalogSearch;
use App\Domains\Catalog\Support\CatalogCache;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Sellers\Enums\SellerStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes the cached counts shown in the mega menu, category tabs, hubs and Game Index, and each product's
 * lowest price / live offer count (spec 19: catalog:recount). Idempotent: always recomputes from source rows.
 * Live offers = active offers of public stores; offers without a catalog product count for their game×category.
 */
final class RecountCatalog
{
    public function __construct(private readonly CatalogSearch $search) {}

    public function handle(): void
    {
        $reindex = [];

        DB::transaction(function () use (&$reindex): void {
            $reindex = $this->products();

            $perCategory = DB::table('products')
                ->select('game_category_id')
                ->selectRaw('COUNT(*) as products')
                ->selectRaw('COALESCE(SUM(offer_count_cached), 0) as offers')
                ->where('status', ProductStatus::Active->value)
                ->whereNull('deleted_at')
                ->groupBy('game_category_id')
                ->get()
                ->keyBy('game_category_id');

            $unitOffers = self::liveOffers()
                ->whereNull('offers.product_id')
                ->select('offers.game_category_id')
                ->selectRaw('COUNT(*) as offers')
                ->groupBy('offers.game_category_id')
                ->pluck('offers', 'game_category_id');

            GameCategory::query()->select(['id', 'game_id', 'product_count_cached', 'offer_count_cached'])->chunkById(500, function ($rows) use ($perCategory, $unitOffers): void {
                foreach ($rows as $row) {
                    $stats = $perCategory->get($row->id);
                    $products = self::int($stats->products ?? 0);
                    $offers = self::int($stats->offers ?? 0) + self::int($unitOffers->get($row->id, 0));

                    if ($row->product_count_cached !== $products || $row->offer_count_cached !== $offers) {
                        GameCategory::query()->whereKey($row->id)->update(['product_count_cached' => $products, 'offer_count_cached' => $offers]);
                    }
                }
            });

            $perGame = DB::table('game_categories')
                ->select('game_id')
                ->selectRaw('COALESCE(SUM(product_count_cached), 0) as products')
                ->selectRaw('COALESCE(SUM(offer_count_cached), 0) as offers')
                ->where('is_enabled', true)
                ->groupBy('game_id')
                ->get()
                ->keyBy('game_id');

            Game::query()->select(['id'])->chunkById(500, function ($games) use ($perGame): void {
                foreach ($games as $game) {
                    $stats = $perGame->get($game->id);
                    Game::query()->whereKey($game->id)->update([
                        'product_count_cached' => self::int($stats->products ?? 0),
                        'offer_count_cached' => self::int($stats->offers ?? 0),
                    ]);
                }
            });
        });

        // Products whose price or offer count changed go back to the search index.
        foreach (array_chunk($reindex, 200) as $ids) {
            foreach (Product::query()->whereIn('id', $ids)->with(['gameCategory', 'platform', 'region'])->get() as $product) {
                $this->search->index($product);
            }
        }

        CatalogCache::flush();
    }

    /**
     * Product lowest price and offer count from live offers. Returns the ids that changed.
     *
     * @return list<int>
     */
    private function products(): array
    {
        $live = self::liveOffers()
            ->whereNotNull('offers.product_id')
            ->select('offers.product_id')
            ->selectRaw('COUNT(*) as offers')
            ->selectRaw('MIN(offers.unit_price_amount) as lowest')
            ->groupBy('offers.product_id')
            ->get()
            ->keyBy('product_id');

        $changed = [];

        DB::table('products')->select(['id', 'offer_count_cached', 'lowest_price_amount_cached'])->orderBy('id')->chunk(1000, function ($rows) use ($live, &$changed): void {
            foreach ($rows as $row) {
                $stats = $live->get(self::int($row->id));
                $count = self::int($stats->offers ?? 0);
                $lowest = $stats === null ? null : self::int($stats->lowest);

                if (self::int($row->offer_count_cached) !== $count || ($row->lowest_price_amount_cached === null ? null : self::int($row->lowest_price_amount_cached)) !== $lowest) {
                    DB::table('products')->where('id', $row->id)->update(['offer_count_cached' => $count, 'lowest_price_amount_cached' => $lowest]);
                    $changed[] = self::int($row->id);
                }
            }
        });

        return $changed;
    }

    private static function liveOffers(): Builder
    {
        return DB::table('offers')
            ->join('seller_profiles', 'seller_profiles.id', '=', 'offers.seller_profile_id')
            ->where('offers.status', OfferStatus::Active->value)
            ->whereNull('offers.deleted_at')
            ->where('offers.currency', 'USD')
            ->whereIn('seller_profiles.status', [SellerStatus::Active->value, SellerStatus::Vacation->value]);
    }

    /** Aggregates come back as strings or ints depending on the driver. */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
