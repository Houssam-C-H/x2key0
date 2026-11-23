<?php

declare(strict_types=1);

namespace App\Domains\Orders\StateMachines;

use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Exceptions\OfferException;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\StateMachines\OfferStateMachine;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\OrderTimerType;
use App\Domains\Orders\Enums\SellerSubstatus;
use App\Domains\Orders\Events\OrderCancelled;
use App\Domains\Orders\Events\OrderPaid;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderStatusHistory;
use App\Domains\Orders\Models\OrderTimer;
use App\Domains\Orders\Support\StockReservations;
use App\Domains\Payments\Contracts\CreditsWallet;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Risk\Models\RiskFlag;
use App\Domains\Storage\Actions\RefreshStorageStock;
use App\Domains\Storage\Models\StorageGroup;
use App\Shared\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of orders.status / seller_substatus (spec 6.1). Each transition locks the order row,
 * checks the transition table, applies its side effects and writes an order_status_history row in one
 * transaction; domain events dispatch after commit.
 *
 * M6 covers the payment part: opened (→ pending_payment), sendToReview, markPaid, cancel before payment.
 * Cancelling a paid order (refunds) arrives with M8.
 */
final class OrderStateMachine
{
    public function __construct(
        private readonly StockReservations $reservations,
        private readonly CreditsWallet $wallet,
        private readonly Settings $settings,
        private readonly OfferStateMachine $offers,
        private readonly RefreshStorageStock $storage,
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

    /**
     * Cancels an order that is not paid yet: stock and credits go back, open PSP payments are cancelled.
     * A PSP part already captured (staff rejecting a payment review) is flagged for refund (M8).
     */
    public function cancel(Order $order, string $reason, ActorType $actor = ActorType::System, ?User $by = null): Order
    {
        if (! in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::PaymentReview], true)) {
            throw CheckoutException::because('order.error.transition', ['from' => $order->status->value, 'to' => OrderStatus::Cancelled->value]);
        }

        $cancelled = $this->transition($order, OrderStatus::Cancelled, $actor, $by, $reason, [], function (Order $o) use ($reason): void {
            $o->loadMissing(['item', 'buyer']);
            $o->cancelled_at = now();
            $o->cancel_reason = mb_substr($reason, 0, 64);

            $this->reservations->release($o->item);

            if ($o->paid_credits_amount > 0) {
                $this->wallet->restore($o->buyer, $o->paid_credits_amount, $o->currency, "CREDITS_RESTORED:order:{$o->id}", $o);
            }

            Payment::query()->where('order_id', $o->id)
                ->whereIn('status', [PaymentStatus::Created->value, PaymentStatus::Pending->value, PaymentStatus::RequiresAction->value, PaymentStatus::Authorized->value])
                ->update(['status' => PaymentStatus::Cancelled->value, 'updated_at' => now()]);

            if ($o->paid_psp_amount > 0) {
                RiskFlag::raise('order', $o->id, 'refund_required', 0, ['amount' => $o->paid_psp_amount, 'currency' => $o->currency, 'reason' => $reason]);
            }

            self::closeTimer($o, OrderTimerType::PaymentExpiry);
        });

        $this->refreshStock($cancelled);
        OrderCancelled::dispatch($cancelled->id, $reason);

        return $cancelled;
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

    /**
     * @param  array<string, mixed>  $meta
     * @param  (callable(Order): void)|null  $mutate
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
                $mutate($locked);
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
