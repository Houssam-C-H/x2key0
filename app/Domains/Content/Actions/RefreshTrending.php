<?php

declare(strict_types=1);

namespace App\Domains\Content\Actions;

use App\Domains\Catalog\Support\CatalogCache;
use App\Domains\Content\Support\HomeCache;
use App\Domains\Orders\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * `home:refresh-trending` (spec Part 23, hourly): trend score per game×category = 10 × orders paid in the last 7 days
 * + views in the last 7 days (browsing history of offers and products); a game's hot score is the sum over its
 * categories. Scores feed Hot Game and the Trending modules; staff pins still come first.
 */
final class RefreshTrending
{
    public const int ORDER_WEIGHT = 10;

    /**
     * @return array{categories: int, games: int}
     */
    public function handle(): array
    {
        $since = now()->subDays(7);
        $scores = [];

        $orders = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('offers', 'offers.id', '=', 'order_items.offer_id')
            ->whereNotNull('orders.paid_at')->where('orders.paid_at', '>=', $since)
            ->whereNotIn('orders.status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value])
            ->groupBy('offers.game_category_id')
            ->selectRaw('offers.game_category_id as gc, COUNT(*) as n')->get();
        foreach ($orders as $row) {
            self::add($scores, $row->gc ?? null, (is_numeric($row->n ?? null) ? (int) $row->n : 0) * self::ORDER_WEIGHT);
        }

        foreach ([['offers', 'offer_id'], ['products', 'product_id']] as [$table, $column]) {
            $views = DB::table('browsing_history')
                ->join($table, "{$table}.id", '=', "browsing_history.{$column}")
                ->where('browsing_history.viewed_at', '>=', $since)
                ->groupBy("{$table}.game_category_id")
                ->selectRaw("{$table}.game_category_id as gc, COUNT(*) as n")->get();
            foreach ($views as $row) {
                self::add($scores, $row->gc ?? null, is_numeric($row->n ?? null) ? (int) $row->n : 0);
            }
        }

        DB::transaction(function () use ($scores): void {
            DB::table('game_categories')->where('trend_score', '>', 0)->update(['trend_score' => 0]);
            foreach ($scores as $id => $score) {
                DB::table('game_categories')->where('id', $id)->update(['trend_score' => $score]);
            }

            DB::table('games')->where('hot_score', '>', 0)->update(['hot_score' => 0]);
            $games = DB::table('game_categories')->where('trend_score', '>', 0)->groupBy('game_id')->selectRaw('game_id, SUM(trend_score) as s')->get();
            foreach ($games as $row) {
                if (is_numeric($row->game_id ?? null)) {
                    DB::table('games')->where('id', (int) $row->game_id)->update(['hot_score' => is_numeric($row->s ?? null) ? (int) $row->s : 0]);
                }
            }
        });

        HomeCache::flush();
        CatalogCache::flush();

        return ['categories' => count($scores), 'games' => count(array_unique(array_keys($scores)))];
    }

    /**
     * @param  array<int, int>  $scores
     */
    private static function add(array &$scores, mixed $gameCategoryId, int $points): void
    {
        if (is_numeric($gameCategoryId) && $points > 0) {
            $scores[(int) $gameCategoryId] = ($scores[(int) $gameCategoryId] ?? 0) + $points;
        }
    }
}
