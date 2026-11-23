<?php

declare(strict_types=1);

namespace App\Domains\Orders\Support;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;

/**
 * Who may do what on an order, by state and time (spec 6.1). Used by controllers to show buttons and to
 * refuse requests; the state machine enforces the transitions themselves.
 */
final class OrderRules
{
    /** Buyer may cancel once the delivery deadline passed and nothing was delivered. */
    public static function buyerCanCancel(Order $order): bool
    {
        $order->loadMissing('item');

        return $order->status === OrderStatus::Paid && $order->item->delivered_qty === 0 && self::slaMissed($order);
    }

    /** Buyer may stop a partial delivery once the deadline passed. */
    public static function buyerCanCancelRemainder(Order $order): bool
    {
        $order->loadMissing('item');

        return $order->status === OrderStatus::Delivering && $order->item->remaining() > 0 && self::slaMissed($order);
    }

    /** Seller may cancel a paid order before delivering anything. */
    public static function sellerCanCancel(Order $order): bool
    {
        $order->loadMissing('item');

        return $order->status === OrderStatus::Paid && $order->item->delivered_qty === 0;
    }

    public static function sellerCanDeliver(Order $order): bool
    {
        $order->loadMissing('item');

        return in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivering], true) && $order->item->remaining() > 0;
    }

    /** Buyer inputs and delivery tools appear for the seller only once the order is paid (spec 8.2). */
    public static function sellerSeesBuyerInputs(Order $order): bool
    {
        return $order->paid_at !== null;
    }

    /** Something was delivered that the buyer can reveal. */
    public static function buyerCanReveal(Order $order): bool
    {
        $order->loadMissing('item');

        return $order->item->delivered_qty > 0;
    }

    private static function slaMissed(Order $order): bool
    {
        return $order->delivery_due_at !== null && $order->delivery_due_at->isPast();
    }
}
