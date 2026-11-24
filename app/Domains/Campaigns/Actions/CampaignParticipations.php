<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Campaigns\Models\Campaign;
use App\Domains\Campaigns\Models\CampaignParticipation;
use App\Domains\Coupons\Exceptions\CouponException;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Facades\DB;

/**
 * Sellers apply to a campaign with their own live offers (spec 14.2), at most `max_offers_per_seller`, and only
 * offers in the campaign's games when it names some. Re-applying replaces a pending, rejected or withdrawn
 * application; an approved one must be withdrawn first. Staff approve or reject (merchant managers and admins).
 */
final class CampaignParticipations
{
    /**
     * @param  list<int|string>  $offerPublicIds
     */
    public function apply(SellerProfile $seller, Campaign $campaign, array $offerPublicIds): CampaignParticipation
    {
        if (! $seller->status->isPublic() || ! $campaign->isOpen()) {
            throw CouponException::because('campaign.error.closed');
        }

        $ids = array_values(array_unique(array_map('strval', $offerPublicIds)));
        if ($ids === [] || count($ids) > $campaign->max_offers_per_seller) {
            throw CouponException::because('campaign.error.offer_count', ['max' => $campaign->max_offers_per_seller]);
        }

        $offers = Offer::query()->live()->where('offers.seller_profile_id', $seller->id)->whereIn('offers.public_id', $ids)->with('gameCategory')->get();
        if ($offers->count() !== count($ids)) {
            throw CouponException::because('campaign.error.offers');
        }

        $games = self::ints($campaign->rules['game_ids'] ?? null);
        if ($games !== [] && $offers->contains(fn (Offer $o): bool => ! in_array($o->gameCategory->game_id, $games, true))) {
            throw CouponException::because('campaign.error.games');
        }

        return DB::transaction(function () use ($seller, $campaign, $offers): CampaignParticipation {
            $row = CampaignParticipation::query()->where('campaign_id', $campaign->id)->where('seller_profile_id', $seller->id)->lockForUpdate()->first();
            if ($row?->status === CampaignParticipation::APPROVED) {
                throw CouponException::because('campaign.error.already_approved');
            }

            $row ??= new CampaignParticipation;
            $row->forceFill([
                'campaign_id' => $campaign->id,
                'seller_profile_id' => $seller->id,
                'offer_ids' => array_values($offers->map(static fn (Offer $o): int => $o->id)->all()),
                'status' => CampaignParticipation::APPLIED,
                'reject_reason' => null,
                'decided_by' => null,
                'decided_at' => null,
            ])->save();

            return $row->refresh();
        });
    }

    /**
     * @return list<int>
     */
    private static function ints(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_int')) : [];
    }

    public function withdraw(SellerProfile $seller, Campaign $campaign): void
    {
        CampaignParticipation::query()->where('campaign_id', $campaign->id)->where('seller_profile_id', $seller->id)
            ->whereIn('status', [CampaignParticipation::APPLIED, CampaignParticipation::APPROVED])
            ->update(['status' => CampaignParticipation::WITHDRAWN, 'updated_at' => now()]);
    }

    public function decide(CampaignParticipation $participation, bool $approve, User $staff, ?string $reason = null): CampaignParticipation
    {
        if ($participation->status !== CampaignParticipation::APPLIED) {
            throw CouponException::because('campaign.error.decided');
        }
        if (! $approve && trim((string) $reason) === '') {
            throw CouponException::because('campaign.error.reason');
        }

        $participation->forceFill([
            'status' => $approve ? CampaignParticipation::APPROVED : CampaignParticipation::REJECTED,
            'reject_reason' => $approve ? null : mb_substr(trim((string) $reason), 0, 500),
            'decided_by' => $staff->id,
            'decided_at' => now(),
        ])->save();

        $participation->loadMissing(['seller', 'campaign']);
        Notifier::notify($participation->seller->user_id, $approve ? 'campaign_approved' : 'campaign_rejected', 'merchant', [
            'campaign' => $participation->campaign->translate('name', 'en'),
            'href' => '/seller/campaigns',
        ]);

        return $participation->refresh();
    }
}
