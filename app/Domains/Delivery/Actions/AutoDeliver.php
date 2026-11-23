<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Actions;

use App\Domains\Delivery\Models\AutoDeliveryLog;
use App\Domains\Delivery\Models\Delivery;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Storage\Enums\StorageItemStatus;
use App\Domains\Storage\Models\StorageItem;
use Illuminate\Support\Facades\DB;

/**
 * Auto delivery from Storage (spec 6.1 "paid → delivered: auto delivery from Storage", 8.1): the storage
 * items reserved at checkout are assigned to the order item (at most once — unique index, invariant 4.3 #4),
 * logged in Storage › Delivery Logs, and the order is delivered. If the items are missing, the failure is
 * logged and the seller delivers by hand. Idempotent: a paid order already delivered is left alone.
 */
final class AutoDeliver
{
    public function __construct(private readonly OrderStateMachine $machine) {}

    public function handle(Order $order): Order
    {
        $order->loadMissing('item.offer');
        $item = $order->item;

        if ($item->offer->fulfillment !== Fulfillment::Auto || $order->status !== OrderStatus::Paid || $item->remaining() === 0) {
            return $order;
        }

        return DB::transaction(function () use ($order, $item): Order {
            $units = $item->remaining();
            $items = StorageItem::query()
                ->where('reserved_for_order_item_id', $item->id)
                ->where('status', StorageItemStatus::Reserved)
                ->orderBy('id')
                ->limit($units)
                ->lockForUpdate()
                ->get();

            if ($items->count() < $units) {
                AutoDeliveryLog::query()->forceCreate([
                    'storage_item_id' => null,
                    'order_item_id' => $item->id,
                    'offer_id' => $item->offer_id,
                    'seller_profile_id' => $order->seller_profile_id,
                    'delivered_at' => now(),
                    'result' => 'failed',
                    'error' => "Only {$items->count()} of {$units} reserved item(s) found.",
                ]);

                return $order;
            }

            $delivery = Delivery::query()->forceCreate([
                'order_item_id' => $item->id,
                'quantity' => $units,
                'method' => $item->offer->delivery_method->value,
                'delivered_by_type' => 'system',
                'delivered_by_id' => null,
                'note' => null,
            ]);

            foreach ($items as $storageItem) {
                $storageItem->forceFill([
                    'status' => StorageItemStatus::Assigned,
                    'assigned_order_item_id' => $item->id,
                    'reserved_until' => null,
                ])->save();
                DB::table('delivery_storage_items')->insert(['delivery_id' => $delivery->id, 'storage_item_id' => $storageItem->id]);
                AutoDeliveryLog::query()->forceCreate([
                    'storage_item_id' => $storageItem->id,
                    'order_item_id' => $item->id,
                    'offer_id' => $item->offer_id,
                    'seller_profile_id' => $order->seller_profile_id,
                    'delivered_at' => now(),
                    'result' => 'success',
                    'error' => null,
                ]);
            }

            return $this->machine->recordDelivery($order, $units, ActorType::System, null, ['delivery_id' => $delivery->id, 'auto' => true]);
        });
    }
}
