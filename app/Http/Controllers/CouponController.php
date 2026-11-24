<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Coupons\Actions\ClaimCoupon;
use App\Domains\Coupons\Exceptions\CouponException;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Support\CouponPresenter;
use App\Domains\Identity\Models\User;
use App\Domains\Points\Support\Points;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Coupon Center (spec 14.1, Part 20 "Get shopping coupons" / "Get platform coupons"): claimable public store
 * codes grouped by store, platform coupons (free or bought with X2K Score), the buyer's own coupons, and
 * claiming by code.
 */
final class CouponController
{
    public function shopping(Request $request): Response
    {
        $user = self::user($request);
        $locale = app()->getLocale();

        $coupons = Coupon::query()->live()->where('issuer', Coupon::SELLER)->where('is_public', true)
            ->whereHas('seller', fn ($q) => $q->public())
            ->with('seller')
            ->where(fn ($q) => $q->whereNull('claim_limit_total')->orWhereColumn('claimed_count', '<', 'claim_limit_total'))
            ->orderByDesc('value')->limit(200)->get();
        $scopes = CouponPresenter::scopeTitles($coupons, $locale);
        $mine = CouponPresenter::mine($user, $coupons);

        return Inertia::render('Coupons/Center', [
            'mode' => 'shopping',
            'coupons' => array_values($coupons->map(fn (Coupon $c): array => CouponPresenter::card($c, $locale, $scopes, $mine))->all()),
            'claims' => CouponPresenter::claims($user, Coupon::SELLER, $locale),
            'points' => null,
        ]);
    }

    public function platform(Request $request): Response
    {
        $user = self::user($request);
        $locale = app()->getLocale();

        $coupons = Coupon::query()->live()->where('issuer', Coupon::PLATFORM)
            ->where(fn ($q) => $q->whereNull('claim_limit_total')->orWhereColumn('claimed_count', '<', 'claim_limit_total'))
            ->orderBy('points_cost')->orderByDesc('id')->limit(200)->get();
        $scopes = CouponPresenter::scopeTitles($coupons, $locale);
        $mine = CouponPresenter::mine($user, $coupons);

        return Inertia::render('Coupons/Center', [
            'mode' => 'platform',
            'coupons' => array_values($coupons->map(fn (Coupon $c): array => CouponPresenter::card($c, $locale, $scopes, $mine))->all()),
            'claims' => CouponPresenter::claims($user, Coupon::PLATFORM, $locale),
            'points' => Points::balance($user->id),
        ]);
    }

    /** Free claim (platform coupon or public store code). */
    public function claim(Request $request, ClaimCoupon $claims, string $publicId): RedirectResponse
    {
        $coupon = Coupon::query()->where('public_id', $publicId)->firstOrFail();

        return self::attempt(fn () => $claims->claim(self::user($request), $coupon));
    }

    /** Platform coupon bought with X2K Score (D9: points only ever buy coupons). */
    public function redeem(Request $request, ClaimCoupon $claims, string $publicId): RedirectResponse
    {
        $coupon = Coupon::query()->where('public_id', $publicId)->firstOrFail();

        return self::attempt(fn () => $claims->withPoints(self::user($request), $coupon));
    }

    /** A store code typed on the store page (public or private "Recommend" code). */
    public function code(Request $request, ClaimCoupon $claims): RedirectResponse
    {
        $request->validate([
            'store' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        return self::attempt(fn () => $claims->byCode(self::user($request), $request->string('store')->toString(), $request->string('code')->toString()));
    }

    private static function attempt(callable $claim): RedirectResponse
    {
        try {
            $claim();
        } catch (CouponException $e) {
            return back()->withErrors(['coupon' => $e->getMessage()]);
        }

        return back()->with('success', __('coupon.claimed'));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
