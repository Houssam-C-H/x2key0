<?php

declare(strict_types=1);

namespace App\Domains\Orders\Support;

use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Models\StorageItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Holds stock for an unpaid order (spec 6.1 create → pending_payment: "reserve storage/manual stock (TTL)").
 *
 * - Manual offers: the offer row is locked and `manual_stock_qty` decremented; the units are remembered on the
 *   order item and given back on release.
 * - Auto offers: N available, unfrozen storage items are marked `reserved` for the order item until the
 *   payment deadline. On PostgreSQL the pick uses `FOR UPDATE SKIP LOCKED` (spec 8.1), so two checkouts never
 *   reserve the same item and neither waits on the other.
 *
 * Must run inside the order transaction. Callers refresh offer/storage counters after commit.
 */
final class StockReservations
{
    public function reserve(OrderItem $item, Offer $offer, int $quantity, Carbon $until): void
    {
        if ($offer->fulfillment === Fulfillment::Manual) {
            /** @var Offer $locked */
            $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);

            if (($locked->manual_stock_qty ?? 0) < $quantity) {
                throw CheckoutException::because('checkout.error.stock', field: 'quantity');
            }

            $locked->manual_stock_qty = ($locked->manual_stock_qty ?? 0) - $quantity;
            $locked->saveQuietly();
            $item->forceFill(['reserved_manual_qty' => $quantity])->save();

            return;
        }

        $ids = self::lockAvailable(
            StorageItem::query()
                ->where('storage_group_id', $offer->storage_group_id)
                ->where('status', StorageItemStatus::Available)
                ->whereNull('flagged_at')
                ->orderBy('id')
                ->limit($quantity)
                ->select('id'),
        );

        if (count($ids) < $quantity) {
            throw CheckoutException::because('checkout.error.stock', field: 'quantity');
        }

        StorageItem::query()->whereIn('id', $ids)->update([
            'status' => StorageItemStatus::Reserved->value,
            'reserved_for_order_item_id' => $item->id,
            'reserved_until' => $until,
            'updated_at' => now(),
        ]);
    }

    /** Gives the stock back (cancelled or expired order). Idempotent. */
    public function release(OrderItem $item): void
    {
        if ($item->reserved_manual_qty > 0) {
            /** @var Offer $offer */
            $offer = Offer::withTrashed()->lockForUpdate()->findOrFail($item->offer_id);
            $offer->manual_stock_qty = ($offer->manual_stock_qty ?? 0) + $item->reserved_manual_qty;
            $offer->saveQuietly();
            $item->forceFill(['reserved_manual_qty' => 0])->save();
        }

        StorageItem::query()
            ->where('reserved_for_order_item_id', $item->id)
            ->where('status', StorageItemStatus::Reserved)
            ->update([
                'status' => StorageItemStatus::Available->value,
                'reserved_for_order_item_id' => null,
                'reserved_until' => null,
                'updated_at' => now(),
            ]);
    }

    /** Paid orders keep their storage items until delivery (M8): the payment deadline no longer applies. */
    public function holdForDelivery(OrderItem $item): void
    {
        StorageItem::query()
            ->where('reserved_for_order_item_id', $item->id)
            ->where('status', StorageItemStatus::Reserved)
            ->update(['reserved_until' => null, 'updated_at' => now()]);
    }

    /**
     * @param  Builder<StorageItem>  $query
     * @return list<int>
     */
    private static function lockAvailable(Builder $query): array
    {
        $query = DB::getDriverName() === 'pgsql' ? $query->lock('for update skip locked') : $query->lockForUpdate();

        return array_values(array_map(static fn (StorageItem $i): int => $i->id, $query->get()->all()));
    }
}
