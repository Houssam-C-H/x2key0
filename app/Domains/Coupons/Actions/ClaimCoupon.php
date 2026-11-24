<?php

declare(strict_types=1);

namespace App\Domains\Coupons\Actions;

use App\Domains\Coupons\Exceptions\CouponException;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponClaim;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Exceptions\BalanceOverdrawn;
use App\Domains\Points\Actions\PostPointsJournal;
use App\Domains\Points\Enums\PointsAccountCode;
use App\Domains\Points\Enums\PointsJournalType;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Getting a coupon (spec 14.1, D9): claim a free platform coupon or a public store coupon, enter a store code
 * (public or private "Recommend" code), or buy a platform coupon with X2K Score (`points_cost`; points only ever
 * buy coupons). Under the coupon row lock: total and per-user limits, buyer level. The claim expires at the
 * coupon's end or `valid_days_after_claim` after claiming, whichever comes first.
 */
final class ClaimCoupon
{
    public function __construct(private readonly PostPointsJournal $points) {}

    public function claim(User $user, Coupon $coupon, string $source = 'claim'): CouponClaim
    {
        if ($coupon->points_cost !== null && $source !== 'points') {
            throw CouponException::because('coupon.error.needs_points');
        }
        if ($coupon->issuer === Coupon::SELLER && ! $coupon->is_public && $source === 'claim') {
            throw CouponException::because('coupon.error.unknown');
        }

        return DB::transaction(fn (): CouponClaim => $this->create($user, $coupon, $source));
    }

    /** A store code typed by the buyer on the store page or in the Coupon center. */
    public function byCode(User $user, string $storeSlug, string $code): CouponClaim
    {
        $seller = SellerProfile::query()->public()->where('slug', $storeSlug)->first();
        $coupon = $seller === null ? null : Coupon::query()->where('issuer', Coupon::SELLER)->where('seller_profile_id', $seller->id)
            ->where('code', strtoupper(trim($code)))->first();

        if ($coupon === null) {
            throw CouponException::because('coupon.error.unknown');
        }

        return DB::transaction(fn (): CouponClaim => $this->create($user, $coupon, 'code'));
    }

    public function withPoints(User $user, Coupon $coupon): CouponClaim
    {
        if ($coupon->issuer !== Coupon::PLATFORM || $coupon->points_cost === null || $coupon->points_cost <= 0) {
            throw CouponException::because('coupon.error.unknown');
        }

        try {
            return DB::transaction(function () use ($user, $coupon): CouponClaim {
                $claim = $this->create($user, $coupon, 'points');
                $this->points->handle(PointsJournalType::RedeemCoupon, "REDEEM_COUPON:claim:{$claim->id}", [
                    [PointsAccountCode::User, $user->id, -(int) $coupon->points_cost],
                    [PointsAccountCode::Redeemed, 0, (int) $coupon->points_cost],
                ], 'coupon_claim', $claim->id, 'Coupon '.$coupon->translate('name', 'en'), [$user->id]);

                return $claim;
            });
        } catch (BalanceOverdrawn) {
            throw CouponException::because('coupon.error.points');
        }
    }

    private function create(User $user, Coupon $coupon, string $source): CouponClaim
    {
        /** @var Coupon $locked */
        $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);

        if (! $locked->isLive()) {
            throw CouponException::because('coupon.error.ended');
        }
        if ($locked->issuer === Coupon::SELLER && $locked->seller?->user_id === $user->id) {
            throw CouponException::because('coupon.error.own');
        }
        if ($locked->claim_limit_total !== null && $locked->claimed_count >= $locked->claim_limit_total) {
            throw CouponException::because('coupon.error.sold_out');
        }
        if ($locked->user_level_min !== null && ($user->level->sort ?? 0) < $locked->user_level_min) {
            throw CouponException::because('coupon.error.level');
        }
        $mine = CouponClaim::query()->where('coupon_id', $locked->id)->where('user_id', $user->id)->count();
        if ($mine >= $locked->claim_limit_per_user) {
            throw CouponException::because('coupon.error.already');
        }

        $expires = $locked->valid_to;
        if ($locked->valid_days_after_claim !== null) {
            $byDays = now()->addDays($locked->valid_days_after_claim);
            $expires = $expires instanceof Carbon ? $expires->min($byDays) : $byDays;
        }

        $claim = new CouponClaim;
        $claim->forceFill([
            'coupon_id' => $locked->id,
            'user_id' => $user->id,
            'claimed_at' => now(),
            'expires_at' => $expires,
            'status' => CouponClaim::AVAILABLE,
            'source' => $source,
        ])->save();

        $locked->increment('claimed_count');

        return $claim->refresh();
    }
}
