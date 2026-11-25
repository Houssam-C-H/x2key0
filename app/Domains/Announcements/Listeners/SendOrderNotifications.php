<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Listeners;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Ledger\Events\HoldReleased;
use App\Domains\Ledger\Models\Hold;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Events\OrderCancelled;
use App\Domains\Orders\Events\OrderCompleted;
use App\Domains\Orders\Events\OrderDelivered;
use App\Domains\Orders\Events\OrderPaid;
use App\Domains\Orders\Models\Order;
use Brick\Money\Money;

/**
 * Notification matrix (spec 12.3), inbox part: every order and money event reaches the people it concerns.
 * Messages are translated at display time from `notify.{type}.title|body` with the stored placeholders, so
 * each reader sees them in their own language.
 */
final class SendOrderNotifications
{
    public function paid(OrderPaid $event): void
    {
        $order = self::order($event->orderId);
        Notifier::notify($order->seller->user_id, 'order_new', 'order', self::data($order, true));
    }

    public function delivered(OrderDelivered $event): void
    {
        $order = self::order($event->orderId);
        Notifier::notify($order->buyer_id, 'order_delivered', 'order', self::data($order, false));
    }

    public function completed(OrderCompleted $event): void
    {
        $order = self::order($event->orderId);
        Notifier::notify($order->seller->user_id, 'order_completed', 'order', [...self::data($order, true), 'amount' => self::money($order->seller_net_amount, $order->currency)]);
    }

    public function cancelled(OrderCancelled $event): void
    {
        $order = self::order($event->orderId);

        // Unpaid orders that simply expired are not news to the seller.
        if ($order->paid_at !== null) {
            Notifier::notify($order->seller->user_id, 'order_cancelled', 'order', [...self::data($order, true), 'reason' => $event->reason]);
        }
        // An unpaid order that timed out is a checkout the buyer did not finish: remind them, pointing back to the
        // offer while it is still on sale (e-mailed at most once a day, Notifier::COOLDOWN_MINUTES).
        if ($order->paid_at === null && $event->reason === 'payment_expired') {
            $order->loadMissing(['item', 'buyer']);
            $offer = Offer::query()->withTrashed()->find($order->item->offer_id);
            $live = $offer !== null && Offer::query()->live()->whereKey($offer->id)->exists();
            Notifier::notify($order->buyer_id, 'checkout_abandoned', 'order', [
                'number' => $order->number,
                'title' => $offer !== null ? $offer->title : $order->number,
                'href' => $live ? $offer->path($order->buyer->locale ?: 'en') : '/buy/orders/'.$order->public_id,
            ]);

            return;
        }

        Notifier::notify($order->buyer_id, 'order_cancelled', 'order', [...self::data($order, false), 'reason' => $event->reason]);
    }

    public function holdReleased(HoldReleased $event): void
    {
        $hold = Hold::query()->with('order')->find($event->holdId);
        if ($hold === null) {
            return;
        }

        Notifier::notify($hold->owner_id, $hold->kind === Hold::RESERVE ? 'reserve_released' : 'funds_released', 'merchant', [
            'amount' => self::money($hold->amount, $hold->currency),
            'number' => $hold->order->number ?? '',
            'href' => '/seller/orders'.($hold->order === null ? '' : "/{$hold->order->public_id}"),
        ]);
    }

    private static function order(int $id): Order
    {
        return Order::query()->with('seller')->findOrFail($id);
    }

    /**
     * @return array<string, string|int>
     */
    private static function data(Order $order, bool $forSeller): array
    {
        return [
            'number' => $order->number,
            'href' => ($forSeller ? '/seller/orders/' : '/buy/orders/').$order->public_id,
        ];
    }

    private static function money(int $cents, string $currency): string
    {
        return $currency.' '.Money::ofMinor($cents, $currency)->getAmount();
    }
}
