<?php

declare(strict_types=1);

namespace App\Domains\Storage\Support;

use App\Domains\Storage\Models\LowStockAlert;

/**
 * The seller stock bar (spec 1.1: "The listing of {offer titles} have insufficient product storage, please
 * check and replenish" + ×): open alerts the seller has not dismissed.
 */
final class StockAlerts
{
    private const int MAX_TITLES = 5;

    /**
     * @return array{count: int, titles: list<string>}|null
     */
    public static function bar(int $sellerProfileId): ?array
    {
        $alerts = LowStockAlert::query()
            ->open()
            ->whereNull('dismissed_at')
            ->where('seller_profile_id', $sellerProfileId)
            ->whereHas('offer')
            ->with('offer:id,title')
            ->orderBy('triggered_at')
            ->get();

        if ($alerts->isEmpty()) {
            return null;
        }

        return [
            'count' => $alerts->count(),
            'titles' => array_values($alerts->take(self::MAX_TITLES)->map(fn (LowStockAlert $a): string => $a->offer->title)->all()),
        ];
    }

    public static function dismiss(int $sellerProfileId): int
    {
        return LowStockAlert::query()
            ->open()
            ->whereNull('dismissed_at')
            ->where('seller_profile_id', $sellerProfileId)
            ->update(['dismissed_at' => now()]);
    }
}
