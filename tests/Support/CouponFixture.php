<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Coupons\Models\Coupon;
use App\Domains\Identity\Models\User;
use App\Domains\Points\Actions\PostPointsJournal;
use App\Domains\Points\Enums\PointsAccountCode;
use App\Domains\Points\Enums\PointsJournalType;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Str;

/** Coupons for tests (spec 14.1): platform coupons, store codes, points. */
final class CouponFixture
{
    /**
     * @param  array<string, mixed>  $attrs
     */
    public static function platform(array $attrs = []): Coupon
    {
        $coupon = new Coupon;
        $coupon->forceFill([
            'issuer' => Coupon::PLATFORM,
            'seller_profile_id' => null,
            'code' => null,
            'is_public' => true,
            'name' => ['en' => 'Welcome $3', 'fr' => 'Bienvenue 3 $'],
            'type' => Coupon::FIXED,
            'value' => 300,
            'max_discount_amount' => null,
            'min_order_amount' => 0,
            'currency' => 'USD',
            'scope' => null,
            'claim_limit_total' => null,
            'claim_limit_per_user' => 1,
            'points_cost' => null,
            'user_level_min' => null,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addMonth(),
            'valid_days_after_claim' => null,
            'status' => 'active',
            'stackable' => true,
            ...$attrs,
        ])->save();

        return $coupon->refresh();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public static function store(SellerProfile $seller, array $attrs = []): Coupon
    {
        return self::platform([
            'issuer' => Coupon::SELLER,
            'seller_profile_id' => $seller->id,
            'code' => 'SAVE10',
            'name' => ['en' => 'SAVE10'],
            'type' => Coupon::PERCENT,
            'value' => 1000,
            ...$attrs,
        ]);
    }

    public static function givePoints(User $user, int $points): void
    {
        app(PostPointsJournal::class)->handle(PointsJournalType::Adjust, 'TEST:'.Str::random(10), [
            [PointsAccountCode::Issued, 0, -$points],
            [PointsAccountCode::User, $user->id, $points],
        ], 'user', $user->id, 'test');
    }
}
