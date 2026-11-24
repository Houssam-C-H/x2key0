<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Campaigns\Actions\CampaignParticipations;
use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Campaigns\Models\CampaignParticipation;
use App\Domains\Coupons\Exceptions\CouponException;
use App\Domains\Offers\Models\Offer;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Marketing Campaign (spec 14.2, 1.8): open platform campaigns, apply with offers, see the decision, withdraw. */
final class CampaignController
{
    use ResolvesSeller;

    public function index(Request $request): Response
    {
        $seller = $this->seller($request);
        $locale = app()->getLocale();
        $campaigns = Campaign::query()->open()->orderBy('starts_at')->limit(50)->get();
        $mine = CampaignParticipation::query()->where('seller_profile_id', $seller->id)->whereIn('campaign_id', $campaigns->pluck('id'))->get()->keyBy('campaign_id');
        $offers = Offer::query()->live()->where('offers.seller_profile_id', $seller->id)->orderByDesc('offers.id')->limit(300)->get(['offers.id', 'offers.public_id', 'offers.title']);
        $publicIds = [];
        foreach ($offers as $offer) {
            $publicIds[$offer->id] = $offer->public_id;
        }

        return Inertia::render('Seller/Campaigns/Index', [
            'campaigns' => array_values($campaigns->map(static function (Campaign $c) use ($mine, $locale, $publicIds): array {
                $p = $mine->get($c->id);

                return [
                    'id' => $c->id,
                    'name' => $c->translate('name', $locale),
                    'description' => $c->description === null ? null : $c->translate('description', $locale),
                    'type' => $c->type,
                    'startsAt' => $c->starts_at->toIso8601String(),
                    'endsAt' => $c->ends_at->toIso8601String(),
                    'running' => $c->starts_at->isPast(),
                    'maxOffers' => $c->max_offers_per_seller,
                    'subsidyBps' => $c->platform_subsidy_bps,
                    'participation' => $p === null ? null : [
                        'status' => $p->status,
                        'offers' => array_values(array_filter(array_map(static fn (int $id): ?string => $publicIds[$id] ?? null, $p->offer_ids))),
                        'rejectReason' => $p->reject_reason,
                    ],
                ];
            })->all()),
            'offers' => array_values($offers->map(static fn (Offer $o): array => ['id' => $o->public_id, 'title' => $o->title])->all()),
        ]);
    }

    public function apply(Request $request, CampaignParticipations $participations, int $campaign): RedirectResponse
    {
        $request->validate(['offers' => ['required', 'array', 'max:100'], 'offers.*' => ['string', 'size:26']]);
        $offers = array_values(array_filter($request->array('offers'), 'is_string'));

        try {
            $participations->apply($this->seller($request), Campaign::query()->findOrFail($campaign), $offers);
        } catch (CouponException $e) {
            return back()->withErrors(['offers' => $e->getMessage()]);
        }

        return back()->with('success', __('campaign.applied'));
    }

    public function withdraw(Request $request, CampaignParticipations $participations, int $campaign): RedirectResponse
    {
        $participations->withdraw($this->seller($request), Campaign::query()->findOrFail($campaign));

        return back()->with('success', __('campaign.withdrawn'));
    }
}
