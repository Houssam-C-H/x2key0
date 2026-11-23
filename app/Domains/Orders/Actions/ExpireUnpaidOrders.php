<?php

declare(strict_types=1);

namespace App\Domains\Orders\Actions;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\OrderTimerType;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderTimer;
use App\Domains\Orders\StateMachines\OrderStateMachine;

/**
 * `orders:expire-unpaid` (spec Part 19, every minute): orders whose payment window passed are cancelled
 * ("TRADE CLOSED"), releasing stock and credits. Idempotent: the timer is closed with the cancellation, and
 * orders paid meanwhile are skipped. A capture arriving later is flagged `late_capture` (ApplyPaymentEvent).
 */
final class ExpireUnpaidOrders
{
    public function __construct(private readonly OrderStateMachine $machine) {}

    public function handle(): int
    {
        $expired = 0;

        OrderTimer::query()
            ->where('type', OrderTimerType::PaymentExpiry)
            ->whereNull('processed_at')
            ->where('due_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($timers) use (&$expired): void {
                foreach ($timers as $timer) {
                    $order = Order::query()->find($timer->order_id);

                    if ($order === null || $order->status !== OrderStatus::PendingPayment) {
                        OrderTimer::query()->whereKey($timer->id)->whereNull('processed_at')->update(['processed_at' => now()]);

                        continue;
                    }

                    try {
                        $this->machine->cancel($order, 'payment_expired');
                        $expired++;
                    } catch (CheckoutException) {
                        // Paid or cancelled concurrently: the next run closes the timer.
                    }
                }
            });

        return $expired;
    }
}
