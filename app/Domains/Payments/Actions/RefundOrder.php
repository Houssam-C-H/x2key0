<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Ledger\Actions\PostOrderPayments;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\Refund;
use App\Domains\Payments\Support\PaymentRouter;
use Illuminate\Support\Facades\DB;

/**
 * Refunds units of a paid order (spec 6.1 cancellations, 7.2 REFUND):
 *
 * - amount = unit price × units (seller side) + the matching share of buyer-paid fees (all remaining fees
 *   when the whole order is refunded before any delivery, a proportional floor otherwise);
 * - sources are returned proportionally to how the order was paid (credits part → credits, card part →
 *   the PSP), unless the buyer chose "all to credits" (D8 allows credits from refunds);
 * - one REFUND journal (escrow and fee revenue back, buyer credits / PSP clearing out), one refund row per
 *   destination, the PSP refund requested with an idempotency key.
 *
 * Runs inside the order transition's transaction (the order row is locked by the caller).
 */
final class RefundOrder
{
    public function __construct(
        private readonly PaymentRouter $router,
        private readonly PostOrderPayments $ledger,
    ) {}

    public function handle(Order $order, OrderItem $item, int $units, string $reason, ActorType $actor, ?int $actorId, bool $toCredits = false): void
    {
        if ($units <= 0 || $units > $item->remaining()) {
            throw CheckoutException::because('order.error.refund_units');
        }

        $whole = $item->delivered_qty === 0 && $item->refunded_qty === 0 && $units === $item->quantity;
        $feesLeft = $order->fees() - $order->refunded_fee_amount;
        $feePart = $whole ? $feesLeft : min($feesLeft, intdiv($order->fees() * $units, $item->quantity));
        $sellerPart = $item->unit_price_amount * $units;
        $total = min($sellerPart + $feePart, $order->refundable());

        // Proportional split by payment source, bounded by what each source has left.
        $creditsLeft = $order->paid_credits_amount - $order->refunded_credits_amount;
        $pspLeft = $order->paid_psp_amount - $order->refunded_psp_amount;
        $paid = $order->paid_credits_amount + $order->paid_psp_amount;
        $fromCredits = $paid === 0 ? 0 : min($creditsLeft, intdiv($total * $order->paid_credits_amount, $paid));
        $fromPsp = $total - $fromCredits;
        if ($fromPsp > $pspLeft) {
            $fromCredits += $fromPsp - $pspLeft;
            $fromPsp = $pspLeft;
        }

        // The order row is locked by the caller, so counting this order's refund journals is race-free.
        $seq = LedgerJournal::query()->where('type', JournalType::Refund)->where('source_type', 'order')->where('source_id', $order->id)->count() + 1;
        $key = "REFUND:order:{$order->id}:{$seq}";
        $payment = $fromPsp > 0 ? self::pspPayment($order) : null;

        $this->ledger->refund($order, $key, $sellerPart, $feePart, $fromCredits, $fromPsp, $payment?->provider, $toCredits);

        if ($payment !== null && ! $toCredits) {
            $result = $this->router->provider($payment->provider)->refund($payment, $fromPsp, $key);
            self::row($order, $payment, Refund::ORIGINAL, $fromPsp, $reason, $result->status, $result->providerRefundId, $actor, $actorId);
        }

        $toCreditsAmount = $fromCredits + ($toCredits ? $fromPsp : 0);
        if ($toCreditsAmount > 0) {
            self::row($order, null, Refund::CREDITS, $toCreditsAmount, $reason, 'succeeded', null, $actor, $actorId);
        }

        $item->forceFill(['refunded_qty' => $item->refunded_qty + $units])->save();
        $order->forceFill([
            'refunded_credits_amount' => $order->refunded_credits_amount + $fromCredits,
            'refunded_psp_amount' => $order->refunded_psp_amount + $fromPsp,
            'refunded_fee_amount' => $order->refunded_fee_amount + $feePart,
        ])->save();
    }

    /** Returns a capture that arrived for an order already closed (spec 9.3, ADR-017). */
    public function lateCapture(Order $order, Payment $payment): void
    {
        DB::transaction(function () use ($order, $payment): void {
            $key = "REFUND:payment:{$payment->id}";
            $this->ledger->refundLateCapture($payment, $key);
            $result = $this->router->provider($payment->provider)->refund($payment, $payment->amount, $key);
            self::row($order, $payment, Refund::ORIGINAL, $payment->amount, 'late_capture', $result->status, $result->providerRefundId, ActorType::System, null);
        });
    }

    private static function pspPayment(Order $order): Payment
    {
        return Payment::query()->where('order_id', $order->id)->where('provider', '!=', Payment::CREDITS)
            ->where('status', PaymentStatus::Captured)->orderBy('id')->firstOrFail();
    }

    private static function row(Order $order, ?Payment $payment, string $destination, int $amount, string $reason, string $status, ?string $providerId, ActorType $actor, ?int $actorId): void
    {
        (new Refund)->forceFill([
            'order_id' => $order->id,
            'payment_id' => $payment?->id,
            'destination' => $destination,
            'amount' => $amount,
            'currency' => $order->currency,
            'reason' => mb_substr($reason, 0, 255),
            'status' => $status,
            'provider_refund_id' => $providerId,
            'initiated_by_type' => $actor->value,
            'initiated_by_id' => $actorId,
        ])->save();
    }
}
