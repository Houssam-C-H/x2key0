<?php

declare(strict_types=1);

namespace App\Domains\Orders\Actions;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Support\StockReservations;
use App\Domains\Storage\Actions\RefreshStorageStock;
use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Models\StorageGroup;
use App\Domains\Storage\Models\StorageItem;
use Illuminate\Support\Facades\DB;

/**
 * `storage:release-expired-reservations` (spec Part 19, safety net every minute): storage items still
 * reserved past their deadline whose order is not waiting for payment (or no longer exists) go back to stock.
 * Paid orders clear the deadline, so their items are never touched; unpaid ones are left to
 * `orders:expire-unpaid`, which cancels with history. Idempotent.
 */
final class ReleaseExpiredReservations
{
    public function __construct(
        private readonly StockReservations $reservations,
        private readonly RefreshStorageStock $refresh,
    ) {}

    public function handle(): int
    {
        $stale = StorageItem::query()
            ->where('status', StorageItemStatus::Reserved)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<', now())
            ->get(['id', 'storage_group_id', 'reserved_for_order_item_id']);

        if ($stale->isEmpty()) {
            return 0;
        }

        $items = OrderItem::query()->whereIn('id', $stale->pluck('reserved_for_order_item_id')->filter()->all())->with('order')->get()->keyBy('id');
        $released = 0;
        $groups = [];

        foreach ($stale as $storageItem) {
            $item = $storageItem->reserved_for_order_item_id === null ? null : $items->get($storageItem->reserved_for_order_item_id);

            if ($item !== null && $item->order->status === OrderStatus::PendingPayment) {
                continue;
            }

            $groups[$storageItem->storage_group_id] = true;

            if ($item !== null) {
                DB::transaction(fn () => $this->reservations->release($item));
            } else {
                StorageItem::query()->whereKey($storageItem->id)->where('status', StorageItemStatus::Reserved)
                    ->update(['status' => StorageItemStatus::Available->value, 'reserved_for_order_item_id' => null, 'reserved_until' => null, 'updated_at' => now()]);
            }
            $released++;
        }

        foreach (StorageGroup::query()->whereIn('id', array_keys($groups))->get() as $group) {
            $this->refresh->handle($group);
        }

        return $released;
    }
}
