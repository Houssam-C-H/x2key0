<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Coupons\Actions\StoreCoupons;
use App\Domains\Coupons\Exceptions\CouponException;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Support\CouponPresenter;
use App\Domains\Sellers\Support\SellerPresenter;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Store coupons (spec 1.8 "My Coupon Codes"): public codes (Product Coupon Settings) and private ones
 * (Recommend Coupons, for marketing e-mails). Add in batch, extend the expiry, end codes.
 */
final class CouponController
{
    use ResolvesSeller;

    public function index(Request $request, bool $private = false): Response
    {
        $seller = $this->seller($request);
        $locale = app()->getLocale();
        $coupons = Coupon::query()->where('issuer', Coupon::SELLER)->where('seller_profile_id', $seller->id)
            ->where('is_public', ! $private)->orderByDesc('id')->limit(500)->get();
        $scopes = CouponPresenter::scopeTitles($coupons, $locale);

        return Inertia::render('Seller/Coupons/Index', [
            'private' => $private,
            'coupons' => array_values($coupons->map(static fn (Coupon $c): array => [
                'id' => $c->public_id,
                'code' => $c->code,
                'label' => $c->label(),
                'scope' => ($id = $c->scope['game_category_ids'][0] ?? null) === null ? null : ($scopes[$id] ?? null),
                'used' => $c->used_count,
                'claimed' => $c->claimed_count,
                'limit' => $c->claim_limit_total,
                'validTo' => $c->valid_to?->toIso8601String(),
                'live' => $c->isLive(),
                'status' => $c->status,
            ])->all()),
            'services' => SellerPresenter::passedServices($seller, $locale),
            'maxPercent' => StoreCoupons::MAX_PERCENT_BPS / 100,
            'maxCodes' => StoreCoupons::MAX_CODES_PER_BATCH,
        ]);
    }

    public function private(Request $request): Response
    {
        return $this->index($request, true);
    }

    public function store(Request $request, StoreCoupons $coupons): RedirectResponse
    {
        $seller = $this->seller($request);
        $request->validate([
            'codes' => ['required', 'string', 'max:2000'],
            'percent' => ['required', 'numeric', 'min:1', 'max:'.(StoreCoupons::MAX_PERCENT_BPS / 100)],
            'game_category_id' => ['nullable', 'integer'],
            'use_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'expires_at' => ['required', 'date'],
            'is_public' => ['required', 'boolean'],
        ]);

        try {
            $created = $coupons->create($seller, [
                'codes' => preg_split('/[\s,;]+/', $request->string('codes')->toString()) ?: [],
                'percent_bps' => (int) round($request->float('percent') * 100),
                'game_category_id' => $request->filled('game_category_id') ? $request->integer('game_category_id') : null,
                'use_limit' => $request->filled('use_limit') ? $request->integer('use_limit') : null,
                'expires_at' => $request->string('expires_at')->toString(),
                'is_public' => $request->boolean('is_public'),
            ]);
        } catch (CouponException $e) {
            return back()->withErrors(['codes' => $e->getMessage()])->withInput();
        }

        return back()->with('success', trans_choice('coupon.seller.created', count($created), ['count' => count($created)]));
    }

    public function extend(Request $request, StoreCoupons $coupons, string $publicId): RedirectResponse
    {
        $request->validate(['expires_at' => ['required', 'date']]);

        try {
            $coupons->extend($this->seller($request), self::coupon($publicId), $request->string('expires_at')->toString());
        } catch (CouponException $e) {
            return back()->withErrors(['expires_at' => $e->getMessage()]);
        }

        return back()->with('success', __('coupon.seller.extended'));
    }

    /** Ends one or several codes ("Batch delete"). */
    public function end(Request $request, StoreCoupons $coupons): RedirectResponse
    {
        $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['string', 'size:26']]);
        $seller = $this->seller($request);

        try {
            foreach (array_filter($request->array('ids'), 'is_string') as $id) {
                $coupons->end($seller, self::coupon($id));
            }
        } catch (CouponException $e) {
            return back()->withErrors(['ids' => $e->getMessage()]);
        }

        return back()->with('success', __('coupon.seller.ended'));
    }

    private static function coupon(string $publicId): Coupon
    {
        return Coupon::query()->where('public_id', $publicId)->firstOrFail();
    }
}
