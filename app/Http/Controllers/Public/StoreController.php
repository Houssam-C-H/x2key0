<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Support\SellerPresenter;
use App\Http\Support\Seo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public store page /{locale}/store/{slug} (spec 1.8 My Store, Part 20). Only active and vacation
 * stores are public; inactive, suspended and banned ones return 404. Offers, reviews and coupons
 * join this page with their milestones (M5, M14, M16).
 */
final class StoreController
{
    public function show(Request $request, string $locale, string $slug): Response
    {
        $profile = SellerProfile::query()->public()->where('slug', $slug)->with(['level', 'stats', 'media', 'user'])->first();
        abort_if($profile === null, 404);

        $viewer = $request->user();
        $bio = $profile->translate('bio', $locale);
        $crumbs = [
            ['name' => __('dashboard.home'), 'href' => "/{$locale}"],
            ['name' => $profile->store_name, 'href' => $profile->path($locale)],
        ];
        $stats = $profile->stats;

        return Inertia::render('Public/Store', [
            'store' => [
                'name' => $profile->store_name,
                'slug' => $profile->slug,
                'logoUrl' => $profile->logoUrl(),
                'bannerUrl' => $profile->bannerUrl(),
                'bio' => $bio === '' ? null : $bio,
                'notice' => ($notice = $profile->translate('store_notice', $locale)) === '' ? null : $notice,
                'country' => $profile->country,
                'level' => SellerPresenter::level($profile->level, $locale),
                'memberSince' => $profile->user->created_at->toIso8601String(),
                'onVacation' => $profile->status === SellerStatus::Vacation,
                'vacationUntil' => $profile->vacation_until?->toDateString(),
                'languages' => array_map(static fn (array $l): array => [
                    'code' => $l['code'],
                    'name' => SellerPresenter::languageName($l['code'], $locale),
                    'level' => $l['level'],
                ], $profile->media->languages ?? []),
                'stats' => [
                    'ordersCompleted' => $stats->orders_completed ?? 0,
                    'completionRateBps' => $stats?->completion_rate_bps,
                    'positiveRateBps' => $stats?->positive_rate_bps,
                    'ratingCount' => $stats->rating_count ?? 0,
                    'disputeRateBps' => self::disputeRate($profile),
                ],
            ],
            'services' => SellerPresenter::passedServices($profile, $locale),
            'isOwner' => $viewer instanceof User && $viewer->id === $profile->user_id,
            'breadcrumbs' => $crumbs,
            'seo' => Seo::make(
                __('seller.public.title', ['store' => $profile->store_name]),
                $bio !== '' ? $bio : __('seller.public.description', ['store' => $profile->store_name]),
                "/store/{$profile->slug}",
                $locale,
                jsonLd: [Seo::breadcrumbList($crumbs)],
            ),
        ]);
    }

    /** Disputes opened per completed order, in bps; null until there are orders (M8+). */
    private static function disputeRate(SellerProfile $profile): ?int
    {
        $stats = $profile->stats;

        if ($stats === null || $stats->orders_completed === 0) {
            return null;
        }

        return intdiv($stats->disputes_opened * 10000, $stats->orders_completed);
    }
}
