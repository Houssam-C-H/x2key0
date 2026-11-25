<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\ServiceType;
use Illuminate\Database\Seeder;

/**
 * Default service types (ADR-032) for the categories whose offers are services rather than catalog products.
 * Idempotent and non-destructive: existing rows (and staff on/off choices, names, order) are never overwritten.
 */
final class ServiceTypeSeeder extends Seeder
{
    /** @var array<string, list<array{string, string, string, string}>> code, en, fr, ar */
    private const array DEFAULTS = [
        'boosting' => [
            ['rank_boost', 'Rank boost', 'Boost de rang', 'رفع الرتبة'],
            ['win_boost', 'Win boost', 'Boost de victoires', 'زيادة الانتصارات'],
            ['placement_matches', 'Placement matches', 'Matchs de placement', 'مباريات التصنيف'],
            ['leveling', 'Leveling', 'Montée de niveau', 'رفع المستوى'],
            ['raids_dungeons', 'Raids & dungeons', 'Raids et donjons', 'الغارات والزنزانات'],
            ['achievements', 'Achievements & unlocks', 'Succès et déblocages', 'الإنجازات وفتح المحتوى'],
            ['coaching', 'Coaching', 'Coaching', 'تدريب'],
        ],
        'accounts' => [
            ['starter', 'Starter account', 'Compte de départ', 'حساب مبتدئ'],
            ['ranked_ready', 'Ranked-ready', 'Prêt pour le classé', 'جاهز للتصنيف'],
            ['high_level', 'High level', 'Haut niveau', 'مستوى عالٍ'],
            ['rare_skins', 'Rare skins', 'Skins rares', 'أشكال نادرة'],
        ],
        'items' => [
            ['cosmetics', 'Skins & cosmetics', 'Skins et cosmétiques', 'الأشكال والمظاهر'],
            ['gear', 'Weapons & gear', 'Armes et équipement', 'الأسلحة والعتاد'],
            ['materials', 'Materials', 'Matériaux', 'المواد'],
            ['mounts_pets', 'Mounts & pets', 'Montures et familiers', 'المطايا والحيوانات الأليفة'],
        ],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $type => $services) {
            $categoryType = CategoryType::query()->where('code', CategoryTypeCode::from($type))->first();
            if ($categoryType === null) {
                continue;
            }

            foreach ($services as $sort => [$code, $en, $fr, $ar]) {
                ServiceType::query()->firstOrCreate(
                    ['category_type_id' => $categoryType->id, 'code' => $code],
                    ['name' => ['en' => $en, 'fr' => $fr, 'ar' => $ar], 'description' => null, 'sort' => $sort, 'is_enabled' => true],
                );
            }
        }
    }
}
