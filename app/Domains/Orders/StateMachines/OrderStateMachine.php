<?php

declare(strict_types=1);

namespace App\Domains\Orders\StateMachines;

use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\SettleOrder;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\StateMachines\OfferStateMachine;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\OrderTimerType;
use App\Domains\Orders\Enums\SellerSubstatus;
use App\Domains\Orders\Events\OrderCancelled;
use App\Domains\Orders\Events\OrderCompleted;
use App\Domains\Orders\Events\OrderDelivered;
use App\Domains\Orders\Events\OrderPaid;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderStatusHistory;
use App\Domains\Orders\Models\OrderTimer;
use App\Domains\Orders\Support\StockReservations;
use App\Domains\Payments\Actions\RefundOrder;
use App\Domains\Payments\Contracts\CreditsWallet;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Points\Actions\AwardOrderPoints;
use App\Domains\Sellers\Models\SellerStats;
use App\Domains\Storage\Actions\RefreshStorageStock;
use App\Domains\Storage\Models\StorageGroup;
use App\Shared\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of orders.status / seller_substatus (spec 6.1). Each change locks the order row, checks
 * the transition table, applies its side effects (stock, credits, refunds, timers) and writes an
 * order_status_history row in one transaction; domain events dispatch after commit.
 *
 * Covered: opened → pending_payment; payment_review; paid; seller sub-status new → restocking → in_trading
 * (⇄ issue); deliveries (paid → delivering → delivered, partial units logged in the timeline); cancellation
 * before delivery (unpaid: credits back; paid: full refund); cancel remainder (refund of undelivered units →
 * delivered). Completion and disputes follow in M9 / M11.
 */
final class OrderStateMachine
{
    /** Seller sub-status moves (spec 6.1); `issue` overrides until cleared. */
    private const array SUBSTATUS_NEXT = [
        'new' => ['restocking', 'in_trading', 'issue'],
        'restocking' => ['in_trading', 'issue'],
        'in_trading' => ['issue'],
        'issue' => ['restocking', 'in_trading'],
    ];

    public function __construct(
        private readonly StockReservations $reservations,
        private readonly CreditsWallet $wallet,
        private readonly Settings $settings,
        private readonly OfferStateMachine $offers,
        private readonly RefreshStorageStock $storage,
        private readonly RefundOrder $refunds,
        private readonly SettleOrder $settlement,
        private readonly AwardOrderPoints $points,
    ) {}

    /** First history row of a new order (created directly in pending_payment by CreateOrder). */
    public function opened(Order $order, User $buyer): void
    {
        $this->history($order, null, OrderStatus::PendingPayment, null, null, ActorType::Buyer, $buyer->id, null, ['number' => $order->number]);
    }

    /**
     * @param  list<string>  $reasons
     */
    public function sendToReview(Order $order, array $reasons): Order
    {
        return $this->transition($order, OrderStatus::PaymentReview, ActorType::System, null, 'risk_review', ['reasons' => $reasons]);
    }

    /** Payment captured and risk passed (system), or staff approved a review. */
    public function markPaid(Order $order, ActorType $actor = ActorType::System, ?User $by = null): Order
    {
        $paid = $this->transition($order, OrderStatus::Paid, $actor, $by, null, [], function (Order $o): void {
            $o->loadMissing('item.offer');
            $now = now();
            $deliveryMinutes = $o->item->offer->delivery_time_minutes;

            $o->paid_at = $now;
            $o->seller_substatus = SellerSubstatus::New;
            $o->delivery_due_at = $now->copy()->addMinutes($deliveryMinutes);

            $this->timer($o, OrderTimerType::AcceptDue, $now->copy()->addMinutes($this->settings->int('accept_due_minutes')));
            $this->timer($o, OrderTimerType::DeliveryDue, $now->copy()->addMinutes($deliveryMinutes));
            $this->timer($o, OrderTimerType::AutoCancel, $now->copy()->addMinutes($deliveryMinutes)->addHours($this->settings->int('auto_cancel_after_sla_hours')));
            self::closeTimer($o, OrderTimerType::PaymentExpiry);

            $this->reservations->holdForDelivery($o->item);
        });

        OrderPaid::dispatch($paid->id);

        return $paid;
    }

    /** Seller acknowledges and works on the order (spec 6.1 sub-status). */
    public function setSubstatus(Order $order, SellerSubstatus $to, ActorType $actor, ?User $by): Order
    {
        return DB::transaction(function () use ($order, $to, $actor, $by): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $locked->seller_substatus;

            if (! in_array($locked->status, [OrderStatus::Paid, OrderStatus::Delivering], true) || $from === null
                || ! in_array($to->value, self::SUBSTATUS_NEXT[$from->value], true)) {
                throw CheckoutException::because('order.error.substatus');
            }

            $locked->seller_substatus = $to;
            if ($to === SellerSubstatus::Restocking || $to === SellerSubstatus::InTrading) {
                $locked->accepted_at ??= now();
                self::closeTimer($locked, OrderTimerType::AcceptDue);
            }
            $locked->save();

            $this->history($locked, $locked->status, $locked->status, $from, $to, $actor, $by?->id, null);

            return $locked->refresh();
        });
    }

    /**
     * Records `units` delivered (DeliverOrder has stored what was delivered): paid → delivering while some
     * units are left, → delivered when everything is delivered or refunded. Runs in the caller's transaction.
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordDelivery(Order $order, int $units, ActorType $actor, ?User $by, array $meta = []): Order
    {
        return DB::transaction(function () use ($order, $units, $actor, $by, $meta): Order {
            /** @var Order $locked */
            $locked = Order::query()->with('item.offer')->lockForUpdate()->findOrFail($order->id);
            $item = $locked->item;

            if (! in_array($locked->status, [OrderStatus::Paid, OrderStatus::Delivering], true) || $units <= 0 || $units > $item->remaining()) {
                throw CheckoutException::because('order.error.deliver_units');
            }

            $item->forceFill(['delivered_qty' => $item->delivered_qty + $units])->save();
            $meta = [...$meta, 'units' => $units, 'delivered' => $item->delivered_qty, 'quantity' => $item->quantity];

            if ($item->remaining() > 0) {
                if ($locked->status === OrderStatus::Delivering) {
                    $this->history($locked, $locked->status, $locked->status, $locked->seller_substatus, $locked->seller_substatus, $actor, $by?->id, 'partial_delivery', $meta);

                    return $locked->refresh();
                }

                return $this->transition($locked, OrderStatus::Delivering, $actor, $by, 'partial_delivery', $meta, function (Order $o): void {
                    $o->seller_substatus = SellerSubstatus::InTrading;
                    $o->accepted_at ??= now();
                });
            }

            $done = $this->transition($locked, OrderStatus::Delivered, $actor, $by, null, $meta, fn (Order $o) => $this->delivered($o));
            OrderDelivered::dispatch($done->id);

            return $done;
        });
    }

    /**
     * Delivered → completed (spec 6.1): the buyer confirms, or the confirmation window ends. Settles the order
     * (ORDER_SETTLEMENT, holds, seller stats) and awards the buyer's points in the same transaction. The system
     * actor is the auto-confirm of the timeline ("The system auto confirm").
     */
    public function complete(Order $order, ActorType $actor, ?User $by = null): Order
    {
        $done = $this->transition($order, OrderStatus::Completed, $actor, $by, $actor === ActorType::System ? 'auto_confirm' : 'buyer_confirm', [], function (Order $o): void {
            $o->loadMissing(['item', 'buyer']);
            // M11: an open dispute will block completion here.
            $o->completed_at = now();
            $this->settlement->handle($o);
            $this->points->earn($o);

            self::closeTimer($o, OrderTimerType::ConfirmDue);
        });

        OrderCompleted::dispatch($done->id);

        return $done;
    }

    /**
     * Cancels an order nothing was delivered for (spec 6.1): unpaid → stock and credits back, open payments
     * cancelled; paid or in review → stock back and a full refund to the original sources (or all to credits).
     */
    public function cancel(Order $order, string $reason, ActorType $actor = ActorType::System, ?User $by = null, bool $toCredits = false): Order
    {
        $order->loadMissing('item');
        if (! in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::PaymentReview, OrderStatus::Paid], true) || $order->item->delivered_qty > 0) {
            throw CheckoutException::because('order.error.transition', ['from' => $order->status->value, 'to' => OrderStatus::Cancelled->value]);
        }

        $cancelled = $this->transition($order, OrderStatus::Cancelled, $actor, $by, $reason, $toCredits ? ['refund_to' => 'credits'] : [], function (Order $o, OrderStatus $from) use ($reason, $actor, $by, $toCredits): void {
            $o->loadMissing(['item', 'buyer']);
            $o->cancelled_at = now();
            $o->cancel_reason = mb_substr($reason, 0, 64);

            $this->reservations->release($o->item);

            if ($from === OrderStatus::PendingPayment) {
                if ($o->paid_credits_amount > 0) {
                    $this->wallet->restore($o->buyer, $o->paid_credits_amount, $o->currency, "CREDITS_RESTORED:order:{$o->id}", $o);
                }

                Payment::query()->where('order_id', $o->id)
                    ->whereIn('status', [PaymentStatus::Created->value, PaymentStatus::Pending->value, PaymentStatus::RequiresAction->value, PaymentStatus::Authorized->value])
                    ->update(['status' => PaymentStatus::Cancelled->value, 'updated_at' => now()]);
            } else {
                $this->refunds->handle($o, $o->item, $o->item->remaining(), $reason, $actor, $by?->id, $toCredits);
            }

            if ($actor === ActorType::Seller) {
                SellerStats::query()->where('seller_profile_id', $o->seller_profile_id)->increment('orders_cancelled_by_seller');
            }

            foreach ([OrderTimerType::PaymentExpiry, OrderTimerType::AcceptDue, OrderTimerType::DeliveryDue, OrderTimerType::AutoCancel] as $type) {
                self::closeTimer($o, $type);
            }
        });

        $this->refreshStock($cancelled);
        OrderCancelled::dispatch($cancelled->id, $reason);

        return $cancelled;
    }

    /** The buyer stops a partly delivered order: undelivered units are refunded and the order is delivered. */
    public function cancelRemainder(Order $order, ActorType $actor, ?User $by, bool $toCredits = false): Order
    {
        $done = $this->transition($order, OrderStatus::Delivered, $actor, $by, 'cancel_remainder', $toCredits ? ['refund_to' => 'credits'] : [], function (Order $o, OrderStatus $from) use ($actor, $by, $toCredits): void {
            $o->loadMissing(['item', 'buyer']);
            if ($from !== OrderStatus::Delivering || $o->item->remaining() === 0) {
                throw CheckoutException::because('order.error.transition', ['from' => $from->value, 'to' => OrderStatus::Delivered->value]);
            }

            $units = $o->item->remaining();
            $this->reservations->release($o->item);
            $this->refunds->handle($o, $o->item, $units, 'cancel_remainder', $actor, $by?->id, $toCredits);
            $this->delivered($o);
        });

        $this->refreshStock($done);
        OrderDelivered::dispatch($done->id);

        return $done;
    }

    /** Re-evaluates the offer's stock after reservations moved (after commit). */
    public function refreshStock(Order $order): void
    {
        DB::afterCommit(function () use ($order): void {
            $order->loadMissing('item');
            $offer = Offer::withTrashed()->with('storageGroup')->find($order->item->offer_id);

            if ($offer === null) {
                return;
            }

            if ($offer->fulfillment === Fulfillment::Auto && $offer->storage_group_id !== null) {
                $group = StorageGroup::query()->find($offer->storage_group_id);
                if ($group !== null) {
                    $this->storage->handle($group);
                }

                return;
            }

            try {
                $this->offers->syncStock($offer);
            } catch (OfferException) {
                // Offer changed meanwhile; the next stock change re-syncs it.
            }
        });
    }

    /** Side effects of reaching `delivered`: confirmation window and protection timers (spec 6.1 / 7.5). */
    private function delivered(Order $o): void
    {
        $o->loadMissing('item.offer');
        $now = now();
        $method = $o->item->offer->delivery_method;
        $window = $method === DeliveryMethod::Service ? $this->settings->int('confirmation_window_hours_services') : $this->settings->int('confirmation_window_hours');

        $o->delivered_at = $now;
        $o->confirm_due_at = $now->copy()->addHours($window);
        $this->timer($o, OrderTimerType::ConfirmDue, $o->confirm_due_at);

        if ($method === DeliveryMethod::AccountTransfer) {
            $days = $o->item->offer->account_recovery_protection_days ?? $this->settings->int('account_recovery_protection_days');
            $this->timer($o, OrderTimerType::RecoveryProtectionEnd, $now->copy()->addDays($days));
        }

        foreach ([OrderTimerType::AcceptDue, OrderTimerType::DeliveryDue, OrderTimerType::AutoCancel] as $type) {
            self::closeTimer($o, $type);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  (callable(Order, OrderStatus): void)|null  $mutate
     */
    private function transition(Order $order, OrderStatus $to, ActorType $actor, ?User $by, ?string $reason, array $meta = [], ?callable $mutate = null): Order
    {
        return DB::transaction(function () use ($order, $to, $actor, $by, $reason, $meta, $mutate): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $locked->status;
            $fromSub = $locked->seller_substatus;

            if (! $from->canTransitionTo($to)) {
                throw CheckoutException::because('order.error.transition', ['from' => $from->value, 'to' => $to->value]);
            }

            $locked->status = $to;
            if ($mutate !== null) {
                $mutate($locked, $from);
            }
            if ($to !== OrderStatus::Paid && $to !== OrderStatus::Delivering) {
                $locked->seller_substatus = null;
            }
            $locked->save();

            $this->history($locked, $from, $to, $fromSub, $locked->seller_substatus, $actor, $by?->id, $reason, $meta);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function history(Order $order, ?OrderStatus $from, OrderStatus $to, ?SellerSubstatus $fromSub, ?SellerSubstatus $toSub, ActorType $actor, ?int $actorId, ?string $reason, array $meta = []): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'from_substatus' => $fromSub,
            'to_substatus' => $toSub,
            'actor_type' => $actor,
            'actor_id' => $actorId,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    private function timer(Order $order, OrderTimerType $type, Carbon $due): void
    {
        OrderTimer::query()->updateOrCreate(['order_id' => $order->id, 'type' => $type], ['due_at' => $due]);
    }

    private static function closeTimer(Order $order, OrderTimerType $type): void
    {
        OrderTimer::query()->where('order_id', $order->id)->where('type', $type)->whereNull('processed_at')->update(['processed_at' => now()]);
    }
}
