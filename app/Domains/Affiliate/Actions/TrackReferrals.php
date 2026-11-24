<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Actions;

use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Affiliate\Models\AffiliateLink;
use App\Domains\Affiliate\Models\AffiliateReferral;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Identity\Support\DeviceCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Attribution (spec Part 15): the first click on an affiliate link sets a cookie (later clicks never replace it);
 * at sign-up the new user is tied to that affiliate for `attribution_days`. Blocked: the affiliate's own devices
 * (self-referral from the same browser) and suspended affiliates.
 */
final class TrackReferrals
{
    public const string COOKIE = 'x2k_ref';

    /**
     * Counts the click; returns the attribution cookie to set on the response, or null when one is already there
     * (first click wins). The caller attaches it to its own response (no shared cookie queue).
     */
    public function click(Request $request, Affiliate $affiliate, ?AffiliateLink $link): ?SymfonyCookie
    {
        Affiliate::query()->whereKey($affiliate->id)->increment('clicks');
        if ($link !== null) {
            AffiliateLink::query()->whereKey($link->id)->increment('clicks');
        }

        if (self::parse($request->cookie(self::COOKIE)) !== null) {
            return null;
        }

        $value = $affiliate->id.'.'.($link->id ?? 0).'.'.now()->getTimestamp();

        return Cookie::make(self::COOKIE, $value, $affiliate->attributionDays() * 24 * 60, httpOnly: true, sameSite: 'lax');
    }

    /** Called right after registration; returns the referral, or null when there is none or it is blocked. */
    public function signup(User $user, Request $request): ?AffiliateReferral
    {
        $cookie = self::parse($request->cookie(self::COOKIE)) ?? self::typedCode($request);
        if ($cookie === null) {
            return null;
        }
        [$affiliateId, $linkId, $clickedAt] = $cookie;

        $affiliate = Affiliate::query()->find($affiliateId);
        if ($affiliate === null || ! $affiliate->isActive() || $affiliate->user_id === $user->id) {
            return null;
        }

        // Same browser as the affiliate's own sign-ins → self-referral.
        $fingerprint = DeviceCookie::fingerprint($request);
        if (UserDevice::query()->where('user_id', $affiliate->user_id)->where('fingerprint', $fingerprint)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($user, $affiliate, $linkId, $clickedAt): ?AffiliateReferral {
            if (AffiliateReferral::query()->where('referred_user_id', $user->id)->exists()) {
                return null;
            }

            $link = $linkId > 0 ? AffiliateLink::query()->where('affiliate_id', $affiliate->id)->find($linkId) : null;
            $referral = new AffiliateReferral;
            $referral->forceFill([
                'affiliate_id' => $affiliate->id,
                'referred_user_id' => $user->id,
                'affiliate_link_id' => $link?->id,
                'first_click_at' => now()->setTimestamp($clickedAt),
                'signed_up_at' => now(),
                'attribution_expires_at' => now()->addDays($affiliate->attributionDays()),
            ])->save();

            $user->forceFill(['referred_by_affiliate_id' => $affiliate->id])->save();
            Affiliate::query()->whereKey($affiliate->id)->increment('signups');
            if ($link !== null) {
                AffiliateLink::query()->whereKey($link->id)->increment('signups');
            }

            return $referral;
        });
    }

    /**
     * A referral code typed on the sign-up form (kept in the session by CreateNewUser) when there was no link click.
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function typedCode(Request $request): ?array
    {
        $code = $request->hasSession() ? $request->session()->get('referral_code') : null;
        if (! is_string($code) || $code === '') {
            return null;
        }
        $id = Affiliate::query()->where('code', strtoupper($code))->value('id');

        return is_numeric($id) ? [(int) $id, 0, now()->getTimestamp()] : null;
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null affiliate id, link id (0 = none), click timestamp
     */
    public static function parse(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^(\d{1,12})\.(\d{1,12})\.(\d{9,11})$/', $value, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
}
