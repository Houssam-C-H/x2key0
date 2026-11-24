<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Sellers\Models\SellerLevel;
use Illuminate\Database\Seeder;

/**
 * Seller tiers (spec 3.3). The entry level matches Part 27 (8% commission, 10% rolling reserve for new
 * sellers). Thresholds feed the recompute job (M16). Values are starting points, editable by admins.
 */
final class SellerLevelSeeder extends Seeder
{
    /** Recompute thresholds (M16): min positive rate, max dispute-loss rate, max violation points. */
    private const array THRESHOLDS = [
        'silver' => [9000, 300, 10],
        'gold' => [9500, 200, 6],
        'platinum' => [9700, 100, 4],
        'diamond' => [9800, 50, 2],
    ];

    public function run(): void
    {
        $levels = [
            ['new', ['en' => 'New seller', 'fr' => 'Nouveau vendeur', 'ar' => 'بائع جديد'], 0, 800, 7, 1000, 50, false, 0, 0, 0],
            ['bronze', ['en' => 'Bronze', 'fr' => 'Bronze', 'ar' => 'برونزي'], 1, 800, 7, 500, 200, false, 1, 20, 9000],
            ['silver', ['en' => 'Silver', 'fr' => 'Argent', 'ar' => 'فضي'], 2, 750, 5, 300, 500, true, 2, 100, 9300],
            ['gold', ['en' => 'Gold', 'fr' => 'Or', 'ar' => 'ذهبي'], 3, 700, 4, 200, 1500, true, 3, 500, 9500],
            ['platinum', ['en' => 'Platinum', 'fr' => 'Platine', 'ar' => 'بلاتيني'], 4, 650, 3, 100, 5000, true, 4, 2000, 9700],
            ['diamond', ['en' => 'Diamond', 'fr' => 'Diamant', 'ar' => 'ماسي'], 5, 600, 3, 0, 20000, true, 5, 10000, 9800],
        ];

        foreach ($levels as $sort => [$code, $name, $stars, $commission, $hold, $reserve, $maxOffers, $autoApprove, $boost, $minOrders, $minRate]) {
            SellerLevel::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'stars' => $stars,
                'sort' => $sort,
                'commission_bps' => $commission,
                'hold_days' => $hold,
                'reserve_bps' => $reserve,
                'max_active_offers' => $maxOffers,
                'auto_approve' => $autoApprove,
                'rank_boost' => $boost,
                'min_orders_completed' => $minOrders,
                'min_completion_rate_bps' => $minRate,
                'min_positive_rate_bps' => self::THRESHOLDS[$code][0] ?? 0,
                'max_dispute_loss_bps' => self::THRESHOLDS[$code][1] ?? null,
                'max_violation_points' => self::THRESHOLDS[$code][2] ?? null,
            ]);
        }
    }
}
