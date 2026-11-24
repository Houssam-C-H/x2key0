<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Campaigns\Actions\SendMarketingEmail;
use App\Domains\Campaigns\Models\MarketingEmail;
use App\Domains\Coupons\Exceptions\CouponException;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Offers\Models\Offer;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Marketing Email (spec 14.3, 1.8): compose → preview (recipients, cost, caps left) → send; Mail promotion records. */
final class MarketingEmailController
{
    use ResolvesSeller;

    public function create(Request $request, SendMarketingEmail $send): Response
    {
        $seller = $this->seller($request);
        [$from, $to] = self::range($request);

        return Inertia::render('Seller/Marketing/Emails', [
            'types' => MarketingEmail::TYPES,
            'offers' => array_values(Offer::query()->live()->where('offers.seller_profile_id', $seller->id)->orderByDesc('offers.id')->limit(300)
                ->get(['offers.public_id', 'offers.title'])->map(static fn (Offer $o): array => ['id' => $o->public_id, 'title' => $o->title])->all()),
            'coupons' => array_values(Coupon::query()->live()->where('issuer', Coupon::SELLER)->where('seller_profile_id', $seller->id)->where('is_public', false)
                ->orderByDesc('id')->limit(100)->get()->map(static fn (Coupon $c): array => ['id' => $c->public_id, 'code' => (string) $c->code, 'label' => $c->label()])->all()),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'preview' => $send->preview($seller, $from, $to),
            'maxOffers' => SendMarketingEmail::MAX_OFFERS,
        ]);
    }

    public function store(Request $request, SendMarketingEmail $send): RedirectResponse
    {
        $request->validate([
            'type' => ['required', 'string'],
            'offers' => ['required', 'array', 'max:'.SendMarketingEmail::MAX_OFFERS],
            'offers.*' => ['string', 'size:26'],
            'coupon' => ['nullable', 'string', 'size:26'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        try {
            $send->handle(
                $this->seller($request),
                $request->string('type')->toString(),
                array_values(array_filter($request->array('offers'), 'is_string')),
                $request->filled('coupon') ? $request->string('coupon')->toString() : null,
                Carbon::parse($request->string('from')->toString())->startOfDay(),
                Carbon::parse($request->string('to')->toString())->endOfDay(),
            );
        } catch (CouponException $e) {
            return back()->withErrors(['send' => $e->getMessage()]);
        }

        return redirect('/seller/marketing/records')->with('success', __('marketing.sent'));
    }

    public function records(Request $request): Response
    {
        $seller = $this->seller($request);

        return Inertia::render('Seller/Marketing/Records', [
            'records' => array_values(MarketingEmail::query()->where('seller_profile_id', $seller->id)->orderByDesc('id')->limit(200)->get()
                ->map(static fn (MarketingEmail $m): array => [
                    'id' => $m->public_id,
                    'type' => $m->type,
                    'quantity' => $m->recipient_count,
                    'unitMillicents' => $m->unit_price_millicents,
                    'total' => $m->total_amount,
                    'status' => $m->status,
                    'createdAt' => $m->sent_at->toIso8601String(),
                    'settledAt' => $m->settled_at?->toIso8601String(),
                ])->all()),
        ]);
    }

    /**
     * Buyers' completed orders in this window (default: the last 90 days).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function range(Request $request): array
    {
        $from = is_string($f = $request->query('from')) && strtotime($f) !== false ? Carbon::parse($f)->startOfDay() : now()->subDays(90)->startOfDay();
        $to = is_string($t = $request->query('to')) && strtotime($t) !== false ? Carbon::parse($t)->endOfDay() : now()->endOfDay();

        return $to->lessThan($from) ? [$to->copy()->startOfDay(), $from->copy()->endOfDay()] : [$from, $to];
    }
}
