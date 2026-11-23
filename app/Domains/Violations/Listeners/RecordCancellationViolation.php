<?php

declare(strict_types=1);

namespace App\Domains\Violations\Listeners;

use App\Domains\Orders\Events\OrderCancelled;
use App\Domains\Orders\Models\Order;
use App\Domains\Violations\Actions\RecordViolation;

/**
 * Automatic violation rules from cancellations (spec 17.2): the seller cancelled a paid order (`seller_cancel`),
 * or the order was cancelled because the delivery deadline passed (`late_delivery`, by the buyer or the system).
 * Runs after commit; RecordViolation fires once per rule × order.
 */
final class RecordCancellationViolation
{
    public function __construct(private readonly RecordViolation $violations) {}

    public function handle(OrderCancelled $event): void
    {
        $code = match (true) {
            str_starts_with($event->reason, 'seller_cancel') => 'seller_cancel',
            in_array($event->reason, ['auto_cancel_sla', 'buyer_cancel_sla'], true) => 'late_delivery',
            default => null,
        };

        if ($code === null) {
            return;
        }

        $order = Order::query()->find($event->orderId);
        if ($order !== null && $order->paid_at !== null) {
            $this->violations->auto($code, $order->seller_profile_id, orderId: $order->id);
        }
    }
}
