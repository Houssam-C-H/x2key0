<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Points\Actions\AwardOrderPoints;
use App\Domains\Points\Models\PointsRule;
use App\Shared\Support\Settings;
use Illuminate\Database\Seeder;

/** Points rules (spec 5.13 / Part 27: `points_per_usd` 10, × buyer level multiplier). */
final class PointsRuleSeeder extends Seeder
{
    public function run(): void
    {
        PointsRule::query()->firstOrCreate(['code' => AwardOrderPoints::RULE], [
            'trigger' => 'order_completed',
            'amount_per_usd' => app(Settings::class)->int('points_per_usd'),
            'fixed' => null,
            'multiplier_by_level' => true,
            'is_enabled' => true,
        ]);
    }
}
