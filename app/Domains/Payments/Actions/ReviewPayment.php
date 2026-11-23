<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Delivery\Actions\AutoDeliver;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Risk\Models\RiskFlag;
use Illuminate\Support\Facades\DB;

/**
 * Staff decision on an order in `payment_review` (spec 6.1): approve → paid (auto-delivered when the offer
 * delivers from Storage); reject → cancelled with a full refund to the original sources. Review flags of
 * the order are resolved by the reviewer.
 */
final class ReviewPayment
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly AutoDeliver $autoDeliver,
    ) {}

    public function approve(Order $order, User $staff): Order
    {
        self::assertInReview($order);
        $paid = DB::transaction(fn (): Order => $this->autoDeliver->handle($this->machine->markPaid($order, ActorType::Staff, $staff)));
        self::resolveFlags($order, $staff);

        return $paid;
    }

    public function reject(Order $order, User $staff, string $reason): Order
    {
        self::assertInReview($order);
        $cancelled = $this->machine->cancel($order, 'risk_rejected: '.$reason, ActorType::Staff, $staff);
        self::resolveFlags($order, $staff);

        return $cancelled;
    }

    private static function assertInReview(Order $order): void
    {
        if ($order->status !== OrderStatus::PaymentReview) {
            throw CheckoutException::because('order.error.transition', ['from' => $order->status->value, 'to' => OrderStatus::Paid->value]);
        }
    }

    private static function resolveFlags(Order $order, User $staff): void
    {
        RiskFlag::query()
            ->where('subject_type', 'order')
            ->where('subject_id', $order->id)
            ->whereIn('rule', ['payment_review', 'checkout_rules'])
            ->where('status', 'open')
            ->update(['status' => 'resolved', 'resolved_by' => $staff->id, 'resolved_at' => now()]);
    }
}
