<?php

declare(strict_types=1);

namespace App\Domains\Coupons\Support;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponClaim;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Collection;

/** Coupon rows for the Coupon Center, the store page, checkout and the seller's code list (spec 14.1, 1.8). */
final class CouponPresenter
{
    /**
     * Claimable coupon card.
     *
     * @param  array<int, string>  $scopes  game category id => title
     * @param  array<int, int>  $mine  coupon id => claims the viewer already has
     * @return array<string, mixed>
     */
    public static function card(Coupon $coupon, string $locale, array $scopes = [], array $mine = []): array
    {
        $gcIds = array_values(array_filter($coupon->scope['game_category_ids'] ?? [], 'is_int'));
        $left = $coupon->claim_limit_total === null ? null : max(0, $coupon->claim_limit_total - $coupon->claimed_count);

        return [
            'id' => $coupon->public_id,
            'issuer' => $coupon->issuer,
            'label' => $coupon->label(),
            'name' => $coupon->translate('name', $locale),
            'code' => $coupon->is_public ? $coupon->code : null,
            'store' => $coupon->issuer === Coupon::SELLER && $coupon->relationLoaded('seller') && $coupon->seller !== null
                ? ['name' => $coupon->seller->store_name, 'href' => $coupon->seller->path($locale)]
                : null,
            'scope' => $gcIds === [] ? null : implode(', ', array_map(static fn (int $id): string => $scopes[$id] ?? '#'.$id, $gcIds)),
            'minOrder' => $coupon->min_order_amount,
            'maxDiscount' => $coupon->max_discount_amount,
            'pointsCost' => $coupon->points_cost,
            'left' => $left,
            'validTo' => $coupon->valid_to?->toIso8601String(),
            'claimed' => ($mine[$coupon->id] ?? 0) >= $coupon->claim_limit_per_user,
        ];
    }

    /**
     * The viewer's claims ("My coupons").
     *
     * @return list<array<string, mixed>>
     */
    public static function claims(User $user, string $issuer, string $locale): array
    {
        $claims = CouponClaim::query()->where('user_id', $user->id)
            ->whereHas('coupon', fn ($q) => $q->where('issuer', $issuer))
            ->with(['coupon.seller'])
            ->orderByDesc('id')->limit(100)->get();

        return array_values($claims->map(static function (CouponClaim $claim) use ($locale): array {
            $coupon = $claim->coupon;
            $status = $claim->status === CouponClaim::AVAILABLE && ! $claim->isUsable() ? CouponClaim::EXPIRED : $claim->status;

            return [
                'id' => $claim->public_id,
                'label' => $coupon->label(),
                'name' => $coupon->translate('name', $locale),
                'store' => $coupon->seller === null ? null : ['name' => $coupon->seller->store_name, 'href' => $coupon->seller->path($locale)],
                'status' => $status,
                'expiresAt' => $claim->expires_at?->toIso8601String(),
                'minOrder' => $coupon->min_order_amount,
            ];
        })->all());
    }

    /**
     * Titles of the game×categories the coupons are limited to, in one query.
     *
     * @param  Collection<int, Coupon>  $coupons
     * @return array<int, string>
     */
    public static function scopeTitles(Collection $coupons, string $locale): array
    {
        $ids = $coupons->flatMap(static fn (Coupon $c): array => array_values(array_filter($c->scope['game_category_ids'] ?? [], 'is_int')))->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $titles = [];
        foreach (GameCategory::query()->with(['game', 'type'])->whereIn('id', $ids)->get() as $gc) {
            $titles[$gc->id] = $gc->title($locale);
        }

        return $titles;
    }

    /**
     * How many claims the user has per coupon.
     *
     * @param  Collection<int, Coupon>  $coupons
     * @return array<int, int>
     */
    public static function mine(?User $user, Collection $coupons): array
    {
        if ($user === null || $coupons->isEmpty()) {
            return [];
        }

        $counts = [];
        $rows = CouponClaim::query()->where('user_id', $user->id)->whereIn('coupon_id', $coupons->pluck('id'))
            ->selectRaw('coupon_id, COUNT(*) as n')->groupBy('coupon_id')->toBase()->get();
        foreach ($rows as $row) {
            $id = $row->coupon_id ?? null;
            $n = $row->n ?? null;
            if (is_numeric($id) && is_numeric($n)) {
                $counts[(int) $id] = (int) $n;
            }
        }

        return $counts;
    }
}
