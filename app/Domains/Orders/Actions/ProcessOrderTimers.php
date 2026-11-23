<?php

declare(strict_types=1);

namespace App\Domains\Orders\Actions;

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\OrderTimerType;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderTimer;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * `orders:process-timers` (spec Part 19, every minute). M8 acts on `auto_cancel`: a paid order with nothing
 * delivered when the SLA grace ends is cancelled and fully refunded. `accept_due` / `delivery_due` are closed
 * (their reminders join the notification matrix in M9/M10); `confirm_due` and `recovery_protection_end` are
 * left for completion (M9). Idempotent: a timer is closed with its effect, and changed orders are skipped.
 */
final class ProcessOrderTimers
{
    private const array HANDLED = [OrderTimerType::AutoCancel, OrderTimerType::AcceptDue, OrderTimerType::DeliveryDue];

    public function __construct(private readonly OrderStateMachine $machine) {}

    /**
     * @return array{auto_cancelled: int, closed: int}
     */
    public function handle(): array
    {
        $cancelled = 0;
        $closed = 0;

        OrderTimer::query()
            ->whereIn('type', array_map(static fn (OrderTimerType $t): string => $t->value, self::HANDLED))
            ->whereNull('processed_at')
            ->where('due_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($timers) use (&$cancelled, &$closed): void {
                foreach ($timers as $timer) {
                    $order = Order::query()->with('item')->find($timer->order_id);

                    if ($timer->type === OrderTimerType::AutoCancel && $order !== null && $order->status === OrderStatus::Paid && $order->item->delivered_qty === 0) {
                        try {
                            $this->machine->cancel($order, 'auto_cancel_sla'); // closes its timers
                            $cancelled++;
                        } catch (CheckoutException) {
                            // Delivered or changed concurrently: the next run re-checks.
                        }

                        continue;
                    }

                    DB::table('order_timers')->where('id', $timer->id)->whereNull('processed_at')->update(['processed_at' => now()]);
                    $closed++;
                }
            });

        return ['auto_cancelled' => $cancelled, 'closed' => $closed];
    }
}
