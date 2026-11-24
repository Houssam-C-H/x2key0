<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Database\Seeder;

/**
 * Local demo only (spec Part 14): two platform coupons (a free $2 welcome coupon and a 10 % coupon bought with
 * 100 X2K Score), seller_demo's public code SAVE10 and private code LOYAL20, and an open flash-sale campaign.
 * Idempotent: rows are matched by name/code.
 */
final class DemoMarketingSeeder extends Seeder
{
    public function run(): void
    {
        self::coupon(['en' => 'Welcome $2', 'fr' => 'Bienvenue 2 $', 'ar' => 'ترحيب 2$'], [
            'type' => Coupon::FIXED, 'value' => 200, 'min_order_amount' => 500, 'points_cost' => null,
        ]);
        self::coupon(['en' => '10 % for X2K Score', 'fr' => '10 % contre des points', 'ar' => '10٪ مقابل النقاط'], [
            'type' => Coupon::PERCENT, 'value' => 1000, 'max_discount_amount' => 1000, 'points_cost' => 100,
        ]);

        $seller = SellerProfile::query()->whereHas('user', fn ($q) => $q->where('username', 'seller_demo'))->first();
        if ($seller !== null) {
            self::coupon(['en' => 'SAVE10'], ['issuer' => Coupon::SELLER, 'seller_profile_id' => $seller->id, 'code' => 'SAVE10', 'type' => Coupon::PERCENT, 'value' => 1000, 'claim_limit_total' => 100]);
            self::coupon(['en' => 'LOYAL20'], ['issuer' => Coupon::SELLER, 'seller_profile_id' => $seller->id, 'code' => 'LOYAL20', 'is_public' => false, 'type' => Coupon::PERCENT, 'value' => 2000, 'claim_limit_total' => 50]);
        }

        Campaign::query()->firstOrCreate(['type' => 'flash_sale', 'status' => 'active'], [
            'name' => ['en' => 'Winter flash sale', 'fr' => 'Vente flash d’hiver', 'ar' => 'تخفيضات الشتاء السريعة'],
            'description' => ['en' => 'Apply with up to 5 discounted offers.', 'fr' => "Postulez avec jusqu'à 5 offres en promotion.", 'ar' => 'قدّم حتى 5 عروض مخفّضة.'],
            'max_offers_per_seller' => 5,
            'platform_subsidy_bps' => 0,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    /**
     * @param  array<string, string>  $name
     * @param  array<string, mixed>  $attrs
     */
    private static function coupon(array $name, array $attrs): void
    {
        $issuer = $attrs['issuer'] ?? Coupon::PLATFORM;
        $exists = $issuer === Coupon::SELLER
            ? Coupon::query()->where('seller_profile_id', $attrs['seller_profile_id'])->where('code', $attrs['code'])->exists()
            : Coupon::query()->where('issuer', Coupon::PLATFORM)->where('name->en', $name['en'])->exists();
        if ($exists) {
            return;
        }

        (new Coupon)->forceFill([
            'issuer' => Coupon::PLATFORM,
            'seller_profile_id' => null,
            'code' => null,
            'is_public' => true,
            'name' => $name,
            'max_discount_amount' => null,
            'min_order_amount' => 0,
            'currency' => 'USD',
            'scope' => null,
            'claim_limit_total' => null,
            'claim_limit_per_user' => 1,
            'points_cost' => null,
            'user_level_min' => null,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addMonths(3),
            'valid_days_after_claim' => null,
            'status' => 'active',
            'stackable' => true,
            ...$attrs,
        ])->save();
    }
}
