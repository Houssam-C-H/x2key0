<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Actions;

use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerStoreMedia;
use App\Shared\Support\Settings;

/**
 * My Store › Featured list (spec 1.8: up to `featured_offers_max` featured offers on the public store).
 * Only the seller's own listed offers qualify; the order given is the display order.
 */
final class SetFeaturedOffers
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  list<int>  $offerIds
     * @return list<int>
     */
    public function handle(SellerProfile $profile, array $offerIds): array
    {
        if ($profile->status === SellerStatus::Suspended) {
            throw SellerException::because('seller.error.suspended');
        }

        $max = $this->settings->int('featured_offers_max');
        $ids = array_values(array_unique($offerIds));

        if (count($ids) > $max) {
            throw SellerException::because('seller.store.featured_max', ['count' => (string) $max]);
        }

        $own = Offer::query()
            ->where('seller_profile_id', $profile->id)
            ->whereIn('status', [OfferStatus::Active->value, OfferStatus::OutOfStock->value])
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        $kept = array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $own, true)));

        $media = SellerStoreMedia::query()->firstOrCreate(['seller_profile_id' => $profile->id], ['languages' => [], 'featured_offer_ids' => []]);
        $media->featured_offer_ids = $kept;
        $media->save();

        return $kept;
    }
}
