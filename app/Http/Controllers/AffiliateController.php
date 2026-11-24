<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Affiliate\Actions\AffiliateAccounts;
use App\Domains\Affiliate\Actions\AffiliateVideos;
use App\Domains\Affiliate\Exceptions\AffiliateException;
use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Affiliate\Models\AffiliateVideoRecord;
use App\Domains\Affiliate\Models\CreativeAsset;
use App\Domains\Affiliate\Support\AffiliatePresenter;
use App\Domains\Identity\Models\User;
use App\Shared\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Affiliate Program screens (spec Part 15, menu "Affiliate Program"): Manage (enrol, balance, transfer to wallet,
 * invitation link, promotional links, custom code), My Affiliates, Promotional video records, Alliance Bill,
 * Creative Assets. Everything but enrolment needs an affiliate profile.
 */
final class AffiliateController
{
    public function manage(Request $request, Settings $settings): Response
    {
        $affiliate = self::affiliate($request);

        return Inertia::render('Affiliate/Manage', [
            'affiliate' => $affiliate === null ? null : AffiliatePresenter::dashboard($affiliate),
            'transferMin' => $settings->int('affiliate_transfer_min'),
            'defaults' => ['direct' => $settings->int('affiliate_commission_bps'), 'level2' => $settings->int('affiliate_level2_bps'), 'days' => $settings->int('affiliate_attribution_days')],
            'idempotencyKey' => strtolower((string) Str::ulid()),
        ]);
    }

    public function enrol(Request $request, AffiliateAccounts $accounts): RedirectResponse
    {
        $accounts->enrol(self::user($request));

        return redirect('/affiliate/manage')->with('success', __('affiliate.enrolled'));
    }

    public function code(Request $request, AffiliateAccounts $accounts): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        return self::attempt(fn () => $accounts->setCode(self::required($request), $request->string('code')->toString()), 'code', __('affiliate.code_saved'));
    }

    public function link(Request $request, AffiliateAccounts $accounts): RedirectResponse
    {
        $request->validate(['target' => ['required', 'string', 'max:500']]);

        return self::attempt(fn () => $accounts->createLink(self::required($request), $request->string('target')->toString()), 'target', __('affiliate.link_created'));
    }

    public function transfer(Request $request, AffiliateAccounts $accounts): RedirectResponse
    {
        $request->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'], 'idempotency_key' => ['required', 'string', 'size:26', 'alpha_num']]);
        $cents = (int) round($request->float('amount') * 100);

        return self::attempt(fn () => $accounts->transferToCredits(self::required($request), $cents, $request->string('idempotency_key')->toString()), 'amount', __('affiliate.transferred'));
    }

    public function referrals(Request $request): Response
    {
        $affiliate = self::required($request);
        $level = $request->integer('level', 1) === 2 ? 2 : 1;

        return Inertia::render('Affiliate/Referrals', [
            'level' => $level,
            'rows' => AffiliatePresenter::referrals($affiliate, $level),
            'counts' => AffiliatePresenter::dashboard($affiliate)['stats'],
        ]);
    }

    public function videos(Request $request): Response
    {
        $affiliate = self::required($request);

        return Inertia::render('Affiliate/Videos', [
            'records' => array_values(AffiliateVideoRecord::query()->where('affiliate_id', $affiliate->id)->orderByDesc('id')->limit(200)->get()
                ->map(static fn (AffiliateVideoRecord $v): array => [
                    'id' => $v->id,
                    'platform' => $v->platform,
                    'url' => $v->url,
                    'views' => $v->views_reported,
                    'status' => $v->status,
                    'reward' => $v->reward_amount,
                    'note' => $v->review_note,
                    'createdAt' => $v->created_at->toIso8601String(),
                ])->all()),
        ]);
    }

    public function submitVideo(Request $request, AffiliateVideos $videos): RedirectResponse
    {
        $request->validate(['url' => ['required', 'string', 'max:500'], 'views' => ['nullable', 'integer', 'min:0']]);

        return self::attempt(fn () => $videos->submit(self::required($request), $request->string('url')->toString(), $request->integer('views')), 'url', __('affiliate.video_submitted'));
    }

    public function bills(Request $request): Response
    {
        $affiliate = self::required($request);
        $month = is_string($m = $request->query('month')) && preg_match('/^\d{4}-\d{2}$/', $m) === 1 ? $m : now()->format('Y-m');
        $from = Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00') ?: now()->startOfMonth();
        $type = in_array($t = $request->query('type'), AffiliatePresenter::BILL_TYPES, true) ? $t : 'all';

        return Inertia::render('Affiliate/Bills', [
            'month' => $month,
            'type' => $type,
            'types' => AffiliatePresenter::BILL_TYPES,
            ...AffiliatePresenter::bill($affiliate, $from->copy()->startOfMonth(), $from->copy()->endOfMonth(), $type),
            'rank' => $affiliate->rank,
        ]);
    }

    public function assets(Request $request): Response
    {
        self::required($request);
        $locale = app()->getLocale();
        $theme = is_string($t = $request->query('theme')) ? $t : null;
        $all = CreativeAsset::query()->where('is_active', true)->where(fn ($q) => $q->whereNull('locale')->orWhere('locale', $locale))->orderBy('theme')->orderBy('type')->get();

        return Inertia::render('Affiliate/Assets', [
            'theme' => $theme,
            'themes' => array_values($all->pluck('theme')->unique()->values()->all()),
            'assets' => array_values($all->filter(static fn (CreativeAsset $a): bool => $theme === null || $a->theme === $theme)->map(static fn (CreativeAsset $a): array => [
                'id' => $a->id,
                'title' => $a->translate('title', $locale),
                'type' => $a->type,
                'theme' => $a->theme,
                'size' => $a->size,
                'url' => $a->file_path === null ? null : Storage::disk('public')->url($a->file_path),
                'copy' => $a->copy_text,
            ])->values()->all()),
        ]);
    }

    private static function attempt(callable $action, string $field, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (AffiliateException $e) {
            return back()->withErrors([$field => $e->getMessage()]);
        }

        return back()->with('success', $success);
    }

    private static function affiliate(Request $request): ?Affiliate
    {
        return Affiliate::query()->where('user_id', self::user($request)->id)->first();
    }

    /** Pages behind enrolment: not enrolled → back to Manage, where the Join button is. */
    private static function required(Request $request): Affiliate
    {
        $affiliate = self::affiliate($request);
        if ($affiliate === null) {
            abort(redirect('/affiliate/manage'));
        }
        abort_if($affiliate->status === Affiliate::SUSPENDED && ! $request->isMethod('GET'), 403);

        return $affiliate;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
