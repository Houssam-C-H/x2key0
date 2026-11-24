<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Affiliate\Actions\AffiliateAccounts;
use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Affiliate\Models\CreativeAsset;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local demo only (spec Part 15): buyer_demo is an affiliate with code DEMO2026 (link `/r/DEMO2026`), plus one
 * ready-made copy asset per language. Idempotent.
 */
final class DemoAffiliateSeeder extends Seeder
{
    public function run(): void
    {
        $buyer = User::query()->where('username', 'buyer_demo')->first();
        if ($buyer !== null) {
            $affiliate = app(AffiliateAccounts::class)->enrol($buyer);
            if (! Affiliate::query()->where('code', 'DEMO2026')->whereKeyNot($affiliate->id)->exists()) {
                $affiliate->forceFill(['code' => 'DEMO2026'])->save();
            }
        }

        $copy = [
            'en' => 'Game keys, top-ups and items from trusted sellers, with buyer protection on every order. Join me on X2KEY:',
            'fr' => 'Clés de jeux, recharges et objets auprès de vendeurs fiables, avec protection acheteur sur chaque commande. Rejoignez-moi sur X2KEY :',
            'ar' => 'مفاتيح ألعاب وشحن وعناصر من بائعين موثوقين مع حماية المشتري في كل طلب. انضم إليّ على X2KEY:',
        ];
        foreach ($copy as $locale => $text) {
            CreativeAsset::query()->firstOrCreate(['type' => 'copy', 'locale' => $locale, 'theme' => 'universal'], [
                'title' => ['en' => 'Short post', 'fr' => 'Message court', 'ar' => 'منشور قصير'],
                'copy_text' => $text,
                'is_active' => true,
            ]);
        }
    }
}
