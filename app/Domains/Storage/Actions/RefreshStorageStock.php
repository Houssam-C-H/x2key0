<?php

declare(strict_types=1);

namespace App\Domains\Storage\Actions;

use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\StateMachines\OfferStateMachine;
use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Models\LowStockAlert;
use App\Domains\Storage\Models\StorageGroup;
use Illuminate\Support\Facades\DB;

/**
 * Recounts a group's available stock, flips linked offers between active and out_of_stock, and opens or
 * resolves low-stock alerts (spec 8.1 / Part 19 `storage:check-low-stock`). Idempotent: running it twice
 * changes nothing the second time.
 */
final class RefreshStorageStock
{
    public function __construct(private readonly OfferStateMachine $machine) {}

    public function handle(StorageGroup $group): StorageGroup
    {
        $group = DB::transaction(function () use ($group): StorageGroup {
            /** @var StorageGroup $locked */
            $locked = StorageGroup::query()->lockForUpdate()->findOrFail($group->id);

            $available = $locked->items()
                ->where('status', StorageItemStatus::Available)
                ->whereNull('flagged_at')
                ->count();

            if ($locked->available_count_cached !== $available) {
                $locked->available_count_cached = $available;
                $locked->saveQuietly(); // a counter, not an audited edit
            }

            return $locked;
        });

        $linked = Offer::query()
            ->where('storage_group_id', $group->id)
            ->where('fulfillment', Fulfillment::Auto)
            ->get();

        $alerting = [];

        foreach ($linked as $offer) {
            $offer->setRelation('storageGroup', $group);

            try {
                $offer = $this->machine->syncStock($offer);
            } catch (OfferException) {
                continue; // changed concurrently; the scheduled run re-checks
            }

            if ($offer->status->tracksStock() && $group->isLow()) {
                $alerting[] = $offer->id;

                $open = LowStockAlert::query()->where('offer_id', $offer->id)->open()->exists();
                if (! $open) {
                    LowStockAlert::query()->create([
                        'seller_profile_id' => $offer->seller_profile_id,
                        'offer_id' => $offer->id,
                        'storage_group_id' => $group->id,
                        'triggered_at' => now(),
                    ]);
                }
            }
        }

        // Everything else that was alerting for this group (restocked, unlinked, deactivated) is resolved.
        LowStockAlert::query()
            ->open()
            ->where('storage_group_id', $group->id)
            ->whereNotIn('offer_id', $alerting)
            ->update(['resolved_at' => now()]);

        return $group;
    }
}
