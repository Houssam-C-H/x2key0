<?php

declare(strict_types=1);

namespace App\Domains\Coupons\Actions;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Coupons\Exceptions\CouponException;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seller coupon codes (spec 1.8 "My Coupon Codes"): percent off, optionally one game×category the store is approved
 * for, use limit, expiry, public (shown on the store page) or private ("Recommend Coupons", for marketing e-mails).
 * Several codes can be created at once (one per line). Codes are unique per store.
 */
final class StoreCoupons
{
    public const int MAX_PERCENT_BPS = 9000;

    public const int MAX_CODES_PER_BATCH = 50;

    /**
     * @param  array{codes: list<string>, percent_bps: int, game_category_id: int|null, use_limit: int|null, expires_at: string, is_public: bool}  $data
     * @return list<Coupon>
     */
    public function create(SellerProfile $seller, array $data): array
    {
        $codes = array_values(array_unique(array_map(static fn (string $c): string => strtoupper(trim($c)), $data['codes'])));
        $codes = array_values(array_filter($codes, static fn (string $c): bool => $c !== ''));

        if ($codes === [] || count($codes) > self::MAX_CODES_PER_BATCH) {
            throw CouponException::because('coupon.error.codes', ['max' => self::MAX_CODES_PER_BATCH]);
        }
        foreach ($codes as $code) {
            if (preg_match('/^[A-Z0-9_-]{4,20}$/', $code) !== 1) {
                throw CouponException::because('coupon.error.code_format', ['code' => $code]);
            }
        }
        if ($data['percent_bps'] < 100 || $data['percent_bps'] > self::MAX_PERCENT_BPS) {
            throw CouponException::because('coupon.error.percent');
        }
        if ($data['game_category_id'] !== null && ! $seller->canSellIn($data['game_category_id'])) {
            throw CouponException::because('coupon.error.scope');
        }

        $expires = Carbon::parse($data['expires_at'])->endOfDay();
        if ($expires->isPast()) {
            throw CouponException::because('coupon.error.expiry');
        }

        return DB::transaction(function () use ($seller, $codes, $data, $expires): array {
            $taken = array_values(array_filter(Coupon::query()->where('seller_profile_id', $seller->id)->whereIn('code', $codes)->pluck('code')->all(), 'is_string'));
            if ($taken !== []) {
                throw CouponException::because('coupon.error.code_taken', ['code' => implode(', ', $taken)]);
            }

            $created = [];
            foreach ($codes as $code) {
                $coupon = new Coupon;
                $coupon->forceFill([
                    'issuer' => Coupon::SELLER,
                    'seller_profile_id' => $seller->id,
                    'code' => $code,
                    'is_public' => $data['is_public'],
                    'name' => ['en' => $code],
                    'type' => Coupon::PERCENT,
                    'value' => $data['percent_bps'],
                    'min_order_amount' => 0,
                    'currency' => 'USD',
                    'scope' => $data['game_category_id'] === null ? null : ['game_category_ids' => [$data['game_category_id']]],
                    'claim_limit_total' => $data['use_limit'],
                    'claim_limit_per_user' => 1,
                    'valid_from' => now(),
                    'valid_to' => $expires,
                    'status' => 'active',
                    'stackable' => true,
                ])->save();
                $created[] = $coupon;
            }

            return $created;
        });
    }

    public function extend(SellerProfile $seller, Coupon $coupon, string $expiresAt): Coupon
    {
        self::assertOwned($seller, $coupon);
        $expires = Carbon::parse($expiresAt)->endOfDay();
        if ($expires->isPast()) {
            throw CouponException::because('coupon.error.expiry');
        }

        $coupon->forceFill(['valid_to' => $expires, 'status' => 'active'])->save();

        return $coupon->refresh();
    }

    /** Ends codes: no new claims, unused claims stop working. Used ones stay on their orders. */
    public function end(SellerProfile $seller, Coupon $coupon): void
    {
        self::assertOwned($seller, $coupon);
        $coupon->forceFill(['status' => 'ended', 'valid_to' => now()])->save();
    }

    public static function gameCategoryLabel(?int $id, string $locale): ?string
    {
        return $id === null ? null : GameCategory::query()->with(['game', 'type'])->find($id)?->title($locale);
    }

    private static function assertOwned(SellerProfile $seller, Coupon $coupon): void
    {
        if ($coupon->issuer !== Coupon::SELLER || $coupon->seller_profile_id !== $seller->id) {
            throw CouponException::because('coupon.error.unknown');
        }
    }
}
