<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Ledger\Actions\PostOrderPayments;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Events\PaymentCaptured;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentEvent;
use App\Domains\Risk\Models\RiskFlag;
use Illuminate\Support\Facades\DB;

/**
 * Applies a stored provider event (spec 9.3 #4–#6), exactly once: the event row is locked and marked
 * processed in the same transaction as its effects.
 *
 * - Unknown payment, stale or out-of-order status → recorded, no change.
 * - Amount or currency different from the payment → risk flag, no transition (9.3 #6).
 * - Captured → payment captured; if the order still awaits payment it goes down the capture path (paid or
 *   payment review, PAYMENT_CAPTURED journal); if it was already closed, a LATE_CAPTURE journal records the
 *   money, the payment is refunded at once and a `late_capture` flag keeps a trace for finance.
 * - Failed / cancelled → payment closed and the unpaid order cancelled (stock and credits back).
 */
final class ApplyPaymentEvent
{
    public function __construct(
        private readonly CapturePath $capture,
        private readonly OrderStateMachine $machine,
        private readonly PostOrderPayments $ledger,
        private readonly RefundOrder $refunds,
    ) {}

    public function handle(int $eventId): void
    {
        DB::transaction(function () use ($eventId): void {
            /** @var PaymentEvent|null $event */
            $event = PaymentEvent::query()->lockForUpdate()->find($eventId);

            if ($event === null || $event->processed_at !== null || ! $event->signature_valid) {
                return;
            }

            $error = $this->apply($event);
            $event->forceFill(['processed_at' => now(), 'error' => $error])->save();
        });
    }

    private function apply(PaymentEvent $event): ?string
    {
        $n = $event->payload['normalized'] ?? null;
        if (! is_array($n) || ! is_string($n['payment_id'] ?? null) || ! is_string($n['status'] ?? null) || ! is_int($n['amount'] ?? null) || ! is_string($n['currency'] ?? null)) {
            return 'malformed_event';
        }

        $status = PaymentStatus::tryFrom($n['status']);
        /** @var Payment|null $payment */
        $payment = Payment::query()->where('provider', $event->provider)->where('provider_payment_id', $n['payment_id'])->lockForUpdate()->first();

        if ($status === null || $payment === null) {
            return 'unknown_payment';
        }

        if ($n['amount'] !== $payment->amount || $n['currency'] !== $payment->currency) {
            RiskFlag::raise('payment', $payment->id, 'payment_mismatch', 100, [
                'expected' => ['amount' => $payment->amount, 'currency' => $payment->currency],
                'received' => ['amount' => $n['amount'], 'currency' => $n['currency']],
                'event_id' => $event->provider_event_id,
            ]);

            return 'amount_mismatch';
        }

        // We cancel payments locally when an order expires; the PSP may still capture. Money moved, so the
        // capture is recorded (the order stays closed and is flagged for refund below).
        $capturedAfterLocalCancel = $status === PaymentStatus::Captured && $payment->status === PaymentStatus::Cancelled;

        if (! $capturedAfterLocalCancel && ! $payment->status->canBecome($status)) {
            return $payment->status === $status ? null : 'stale_status';
        }

        $card = is_array($n['card'] ?? null) ? $n['card'] : [];
        $payment->forceFill([
            'status' => $status,
            'three_ds' => ($n['three_ds'] ?? false) === true,
            'card_bin' => is_string($card['bin'] ?? null) ? mb_substr($card['bin'], 0, 8) : $payment->card_bin,
            'card_last4' => is_string($card['last4'] ?? null) ? mb_substr($card['last4'], 0, 4) : $payment->card_last4,
            'card_country' => is_string($card['country'] ?? null) ? mb_strtoupper(mb_substr($card['country'], 0, 2)) : $payment->card_country,
            'failure_reason' => is_string($n['reason'] ?? null) ? $n['reason'] : $payment->failure_reason,
            'captured_at' => $status === PaymentStatus::Captured ? now() : $payment->captured_at,
        ])->save();

        /** @var Order|null $order */
        $order = $payment->order_id === null ? null : Order::query()->lockForUpdate()->find($payment->order_id);
        if ($order === null) {
            return null;
        }

        if ($status === PaymentStatus::Captured) {
            PaymentCaptured::dispatch($payment->id);

            if ($order->status !== OrderStatus::PendingPayment) {
                RiskFlag::raise('order', $order->id, 'late_capture', 0, ['payment_id' => $payment->id, 'amount' => $payment->amount, 'order_status' => $order->status->value]);
                $this->ledger->lateCapture($payment, $order);
                $this->refunds->lateCapture($order, $payment); // owed back in full: refunded at once

                return 'late_capture';
            }

            $order->forceFill(['paid_psp_amount' => $order->paid_psp_amount + $payment->amount])->save();
            $this->capture->afterCapture($order, $payment);

            return null;
        }

        if (in_array($status, [PaymentStatus::Failed, PaymentStatus::Cancelled], true) && $order->status === OrderStatus::PendingPayment) {
            $this->machine->cancel($order, $status === PaymentStatus::Failed ? 'payment_failed' : 'payment_cancelled');
        }

        return null;
    }
}
