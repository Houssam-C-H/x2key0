<?php

declare(strict_types=1);

namespace App\Domains\Orders\Actions;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\OrderTimerType;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderTimer;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * `orders:process-timers` (spec Part 19, every minute):
 * - `auto_cancel`: a paid order with nothing delivered when the SLA grace ends is cancelled and fully refunded;
 * - `confirm_due`: a delivered order the buyer did not confirm completes by itself ("The system auto confirm");
 * - `accept_due` / `delivery_due` / `recovery_protection_end`: closed (the money side of the recovery window is the
 *   settlement hold; disputes and reminders join with M10/M11).
 * Idempotent: a timer is closed with its effect, and changed orders are skipped.
 */
final class ProcessOrderTimers
{
    private const array HANDLED = [OrderTimerType::AutoCancel, OrderTimerType::AcceptDue, OrderTimerType::DeliveryDue, OrderTimerType::ConfirmDue, OrderTimerType::RecoveryProtectionEnd];

    public function __construct(private readonly OrderStateMachine $machine) {}

    /**
     * @return array{auto_cancelled: int, auto_confirmed: int, closed: int}
     */
    public function handle(): array
    {
        $cancelled = 0;
        $confirmed = 0;
        $closed = 0;

        OrderTimer::query()
            ->whereIn('type', array_map(static fn (OrderTimerType $t): string => $t->value, self::HANDLED))
            ->whereNull('processed_at')
            ->where('due_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($timers) use (&$cancelled, &$confirmed, &$closed): void {
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

                    if ($timer->type === OrderTimerType::ConfirmDue && $order !== null && $order->status === OrderStatus::Delivered) {
                        try {
                            $this->machine->complete($order, ActorType::System); // closes its timer
                            $confirmed++;
                        } catch (CheckoutException) {
                            // Changed concurrently: the next run re-checks.
                        }

                        continue;
                    }

                    DB::table('order_timers')->where('id', $timer->id)->whereNull('processed_at')->update(['processed_at' => now()]);
                    $closed++;
                }
            });

        return ['auto_cancelled' => $cancelled, 'auto_confirmed' => $confirmed, 'closed' => $closed];
    }
}
