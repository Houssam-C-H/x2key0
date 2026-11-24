<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domains\Affiliate\Actions\TrackReferrals;
use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Affiliate\Models\AffiliateLink;
use App\Domains\Identity\Models\User;
use App\Http\Middleware\ResolveLocale;
use App\Http\Support\Seo;
use App\Shared\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Public affiliate landing `/{locale}/affiliate` and referral links `/r/{code}` and `/r/{code}/{slug}` (spec Part 15, Part 20). */
final class AffiliateLandingController
{
    public function show(Request $request, Settings $settings, string $locale): Response
    {
        $user = $request->user();

        return Inertia::render('Public/Affiliate', [
            'rates' => ['direct' => $settings->int('affiliate_commission_bps'), 'level2' => $settings->int('affiliate_level2_bps'), 'days' => $settings->int('affiliate_attribution_days'), 'transferMin' => $settings->int('affiliate_transfer_min')],
            'enrolled' => $user instanceof User && Affiliate::query()->where('user_id', $user->id)->exists(),
            'seo' => Seo::make((string) __('affiliate.landing.title'), (string) __('affiliate.landing.description'), '/affiliate', $locale),
        ]);
    }

    /** Counts the click, sets the first-click cookie and sends the visitor on (only to pages of this site). */
    public function redirect(Request $request, TrackReferrals $referrals, string $code, ?string $slug = null): RedirectResponse
    {
        $affiliate = Affiliate::query()->where('code', strtoupper($code))->first();
        $fallback = '/'.ResolveLocale::preferredLocale($request);

        if ($affiliate === null || ! $affiliate->isActive()) {
            return redirect($fallback);
        }

        $link = $slug === null ? null : AffiliateLink::query()->where('affiliate_id', $affiliate->id)->where('slug', $slug)->first();
        $cookie = $referrals->click($request, $affiliate, $link);
        $response = redirect($link->target_path ?? $fallback);

        return $cookie === null ? $response : $response->withCookie($cookie);
    }
}
