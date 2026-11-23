<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Violations\Enums\Penalty;
use App\Domains\Violations\Models\ViolationRule;
use Illuminate\Database\Seeder;

/**
 * Violation rules (spec 17.2 auto rules + staff rules). Points and penalties are starting values; admins edit them.
 */
final class ViolationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['dispute_lost', ['en' => 'Dispute lost', 'fr' => 'Litige perdu', 'ar' => 'خسارة نزاع'], 6, Penalty::Warning, true],
            ['seller_cancel', ['en' => 'Order cancelled by the seller', 'fr' => 'Commande annulée par le vendeur', 'ar' => 'إلغاء الطلب من البائع'], 3, Penalty::Warning, true],
            ['late_delivery', ['en' => 'Late delivery', 'fr' => 'Livraison en retard', 'ar' => 'تأخر التسليم'], 2, Penalty::Warning, true],
            ['off_platform_contact', ['en' => 'Off-platform contact attempt', 'fr' => 'Tentative de contact hors plateforme', 'ar' => 'محاولة تواصل خارج المنصة'], 10, Penalty::Warning, false],
            ['prohibited_listing', ['en' => 'Prohibited listing', 'fr' => 'Annonce interdite', 'ar' => 'عرض محظور'], 10, Penalty::ListingSuspend, false],
            ['fake_delivery_evidence', ['en' => 'Fake delivery evidence', 'fr' => 'Fausse preuve de livraison', 'ar' => 'إثبات تسليم مزيف'], 30, Penalty::StoreSuspend, false],
            ['fraud', ['en' => 'Fraud', 'fr' => 'Fraude', 'ar' => 'احتيال'], 60, Penalty::Ban, false],
        ];

        foreach ($rules as [$code, $name, $points, $penalty, $auto]) {
            ViolationRule::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'points' => $points,
                'penalty' => $penalty,
                'auto' => $auto,
                'is_enabled' => true,
            ]);
        }
    }
}
