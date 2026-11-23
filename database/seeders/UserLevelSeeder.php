<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Identity\Models\UserLevel;
use Illuminate\Database\Seeder;

/**
 * Buyer levels (spec 3.3). Thresholds are lifetime completed spend in USD cents; tune after launch data.
 */
final class UserLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['code' => 'bronze', 'name' => ['en' => 'Bronze', 'fr' => 'Bronze', 'ar' => 'برونزي'], 'min_spend_amount' => 0, 'points_multiplier_bps' => 10000],
            ['code' => 'silver', 'name' => ['en' => 'Silver', 'fr' => 'Argent', 'ar' => 'فضي'], 'min_spend_amount' => 10_000, 'points_multiplier_bps' => 11000],
            ['code' => 'gold', 'name' => ['en' => 'Gold', 'fr' => 'Or', 'ar' => 'ذهبي'], 'min_spend_amount' => 50_000, 'points_multiplier_bps' => 12000],
            ['code' => 'platinum', 'name' => ['en' => 'Platinum', 'fr' => 'Platine', 'ar' => 'بلاتيني'], 'min_spend_amount' => 200_000, 'points_multiplier_bps' => 13500],
            ['code' => 'diamond', 'name' => ['en' => 'Diamond', 'fr' => 'Diamant', 'ar' => 'ماسي'], 'min_spend_amount' => 1_000_000, 'points_multiplier_bps' => 15000],
        ];

        foreach ($levels as $sort => $level) {
            UserLevel::query()->updateOrCreate(['code' => $level['code']], [...$level, 'sort' => $sort]);
        }
    }
}
