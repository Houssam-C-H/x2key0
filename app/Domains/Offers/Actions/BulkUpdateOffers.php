<?php

declare(strict_types=1);

namespace App\Domains\Offers\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Models\OfferPriceHistory;
use App\Domains\Offers\StateMachines\OfferStateMachine;
use App\Domains\Offers\Support\OfferRules;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Actions\RefreshStorageStock;
use App\Domains\Storage\Models\LowStockAlert;
use Illuminate\Support\Facades\DB;

/**
 * Active Offers batch buttons and inline edits (spec 1.8): Deactivate · Relist · Extend duration · Delete ·
 * inline Unit Price / Sort / Stock. Each offer is handled on its own: one refused transition does not stop
 * the batch. Only the seller's own offers are touched.
 */
final class BulkUpdateOffers
{
    public const array OPERATIONS = ['deactivate', 'relist', 'extend', 'delete'];

    public function __construct(
        private readonly OfferStateMachine $machine,
        private readonly RefreshProductOffers $refreshProduct,
        private readonly RefreshStorageStock $refreshStock,
    ) {}

    /**
     * @param  list<int>  $offerIds
     * @return array{done: int, failed: int}
     */
    public function apply(SellerProfile $profile, User $actor, string $operation, array $offerIds, int $days = 0): array
    {
        self::assertCanWrite($profile);

        $done = 0;
        $failed = 0;

        foreach ($this->own($profile, $offerIds) as $offer) {
            try {
                match ($operation) {
                    'deactivate' => $this->machine->deactivate($offer, $actor),
                    'relist' => $this->machine->publish($offer, $actor),
                    'extend' => $this->extend($offer, $days),
                    'delete' => $this->delete($offer, $actor),
                    default => throw OfferException::because('offer.error.operation'),
                };
                $done++;
            } catch (OfferException) {
                $failed++;
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /** Inline unit price edit (version++, price history). */
    public function price(SellerProfile $profile, User $actor, Offer $offer, int $cents): Offer
    {
        self::assertCanWrite($profile);
        self::assertOwn($profile, $offer);

        if ($cents < 1 || $cents > OfferRules::MAX_PRICE) {
            throw OfferException::because('offer.error.price', field: 'unit_price_amount');
        }

        $offer = DB::transaction(function () use ($offer, $actor, $cents): Offer {
            /** @var Offer $locked */
            $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);

            if ($locked->unit_price_amount === $cents) {
                return $locked;
            }

            // Tiers must stay below the new base price.
            $locked->quantity_tiers = OfferRules::tiers($locked->quantity_tiers ?? [], $cents, $locked->min_qty) ?: null;
            $locked->unit_price_amount = $cents;
            $locked->version++;
            $locked->save();

            OfferPriceHistory::query()->create(['offer_id' => $locked->id, 'unit_price_amount' => $cents, 'currency' => $locked->currency, 'changed_by' => $actor->id]);

            return $locked;
        });

        $this->refreshProduct->handle($offer->product_id);

        return $offer;
    }

    /** Inline Sort number: higher shows first in the seller's own store. */
    public function sort(SellerProfile $profile, Offer $offer, int $weight): Offer
    {
        self::assertCanWrite($profile);
        self::assertOwn($profile, $offer);

        $offer->sort_weight = max(-9999, min(9999, $weight));
        $offer->save();

        return $offer;
    }

    /** Inline stock edit for manually delivered offers; follows stock into/out of out_of_stock. */
    public function stock(SellerProfile $profile, Offer $offer, int $qty): Offer
    {
        self::assertCanWrite($profile);
        self::assertOwn($profile, $offer);

        if ($offer->fulfillment !== Fulfillment::Manual) {
            throw OfferException::because('offer.error.stock_from_storage', field: 'manual_stock_qty');
        }

        $offer->manual_stock_qty = max(0, min(OfferRules::MAX_STOCK, $qty));
        $offer->save();

        return $this->machine->syncStock($offer);
    }

    private function extend(Offer $offer, int $days): void
    {
        if (! in_array($days, OfferRules::LISTING_DAYS, true)) {
            throw OfferException::because('offer.error.operation');
        }

        if ($offer->status === OfferStatus::Expired) {
            $this->machine->publish($offer, null);

            return;
        }

        if (! $offer->status->tracksStock()) {
            throw OfferException::because('offer.error.operation');
        }

        $from = $offer->expires_at !== null && $offer->expires_at->isFuture() ? $offer->expires_at : now();
        $offer->expires_at = $from->copy()->addDays($days);
        $offer->save();
    }

    private function delete(Offer $offer, User $actor): void
    {
        if ($offer->status->sellerCanDeactivate()) {
            $offer = $this->machine->deactivate($offer, $actor);
        }

        DB::transaction(function () use ($offer): void {
            LowStockAlert::query()->where('offer_id', $offer->id)->open()->update(['resolved_at' => now()]);
            $offer->delete();
        });

        $this->refreshProduct->handle($offer->product_id);
        if ($offer->storageGroup !== null) {
            $this->refreshStock->handle($offer->storageGroup);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return iterable<Offer>
     */
    private function own(SellerProfile $profile, array $ids): iterable
    {
        return Offer::query()->where('seller_profile_id', $profile->id)->whereIn('id', array_slice(array_values(array_unique($ids)), 0, 200))->with('storageGroup')->get();
    }

    private static function assertOwn(SellerProfile $profile, Offer $offer): void
    {
        abort_unless($offer->seller_profile_id === $profile->id, 404);
    }

    private static function assertCanWrite(SellerProfile $profile): void
    {
        if ($profile->status === SellerStatus::Suspended) {
            throw OfferException::because('seller.error.suspended');
        }
    }
}
