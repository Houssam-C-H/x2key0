<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Support;

use App\Domains\Certification\Enums\CertificationStatus;
use App\Domains\Certification\Enums\CertificationType;
use App\Domains\Certification\Models\Certification;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Facades\Cache;

/**
 * Public trust badges for a store (offer cards, buy box, store page), from facts X2KEY checked itself:
 *
 * - `verified`  — the seller's identity certification is verified (Settings › Verification).
 * - `top_rated` — ≥ 98 % positive with at least 20 ratings.
 * - `fast`      — average delivery under 30 minutes over completed orders (at least 5).
 * - `years`     — active for a full year or more (value = whole years).
 *
 * Plus `sales` (completed orders). Cached 10 minutes per store, so listings add no query per card.
 */
final class SellerBadges
{
    public const int TOP_RATED_BPS = 9800;

    public const int TOP_RATED_MIN_RATINGS = 20;

    public const int FAST_MINUTES = 30;

    /**
     * @return array{badges: list<string>, years: int, sales: int}
     */
    public static function for(SellerProfile $profile): array
    {
        /** @var array{badges: list<string>, years: int, sales: int} */
        return Cache::remember("seller-badges:{$profile->id}", 600, static function () use ($profile): array {
            $profile->loadMissing('stats');
            $stats = $profile->stats;
            $badges = [];

            $verified = Certification::query()
                ->where('user_id', $profile->user_id)
                ->where('type', CertificationType::Identity)
                ->where('status', CertificationStatus::Verified)
                ->exists();
            if ($verified) {
                $badges[] = 'verified';
            }

            if ($stats !== null && ($stats->positive_rate_bps ?? 0) >= self::TOP_RATED_BPS && $stats->rating_count >= self::TOP_RATED_MIN_RATINGS) {
                $badges[] = 'top_rated';
            }

            if ($stats !== null && $stats->avg_delivery_minutes !== null && $stats->avg_delivery_minutes < self::FAST_MINUTES && $stats->orders_completed >= 5) {
                $badges[] = 'fast';
            }

            $years = $profile->activated_at === null ? 0 : (int) floor($profile->activated_at->diffInYears(now()));
            if ($years >= 1) {
                $badges[] = 'years';
            }

            return ['badges' => $badges, 'years' => $years, 'sales' => $stats->orders_completed ?? 0];
        });
    }

    /** Forget a store's badges (after a certification decision or a stats recompute). */
    public static function forget(int $profileId): void
    {
        Cache::forget("seller-badges:{$profileId}");
    }
}
