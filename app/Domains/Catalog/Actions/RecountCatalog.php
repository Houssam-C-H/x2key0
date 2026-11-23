<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\ProductStatus;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Support\CatalogCache;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes the cached counts shown in the mega menu, category tabs, hubs and Game Index (spec 19: catalog:recount).
 * Idempotent: always recomputes from source rows. Offer counts come from products until offers exist (M5).
 */
final class RecountCatalog
{
    public function handle(): void
    {
        DB::transaction(function (): void {
            $perCategory = DB::table('products')
                ->select('game_category_id')
                ->selectRaw('COUNT(*) as products')
                ->selectRaw('COALESCE(SUM(offer_count_cached), 0) as offers')
                ->where('status', ProductStatus::Active->value)
                ->whereNull('deleted_at')
                ->groupBy('game_category_id')
                ->get()
                ->keyBy('game_category_id');

            GameCategory::query()->select(['id', 'game_id', 'product_count_cached', 'offer_count_cached'])->chunkById(500, function ($rows) use ($perCategory): void {
                foreach ($rows as $row) {
                    $stats = $perCategory->get($row->id);
                    $products = self::int($stats->products ?? 0);
                    $offers = self::int($stats->offers ?? 0);

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

        CatalogCache::flush();
    }

    /** Aggregates come back as strings or ints depending on the driver. */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
