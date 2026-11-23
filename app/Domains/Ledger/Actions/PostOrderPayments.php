<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Actions;

use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;

/**
 * Payment-side journals of an order (spec 7.2), posted in the same transaction as the order change:
 *
 * PAYMENT_CAPTURED (order fully paid; key `PAYMENT_CAPTURED:order:{id}`)
 *   PSP_CLEARING:{provider} +psp   ORDER_ESCROW −psp      (each captured PSP payment)
 *   ORDER_ESCROW +fees             REV_BUYER_FEE −fees    (service charge + add-on + method surcharge)
 *   → with CREDITS_SPENT before it, escrow holds exactly the seller-side price.
 *
 * LATE_CAPTURE (a PSP capture for an order already closed; key `LATE_CAPTURE:payment:{id}`)
 *   PSP_CLEARING:{provider} +amount   ORDER_ESCROW −amount  (owed back to the buyer; refunded in M8)
 *
 * PSP processing costs (EXP_PSP_FEES) join with real PSP drivers (M6b): the fake PSP reports none.
 */
final class PostOrderPayments
{
    public function __construct(private readonly PostJournal $post) {}

    public function captured(Order $order): void
    {
        $currency = $order->currency;
        $escrow = AccountRef::platform(AccountCode::OrderEscrow, $currency);
        $lines = [];

        $payments = Payment::query()->where('order_id', $order->id)->where('provider', '!=', Payment::CREDITS)->where('status', PaymentStatus::Captured)->get();
        foreach ($payments as $payment) {
            $lines[] = [AccountRef::pspClearing($payment->provider, $currency), $payment->amount];
            $lines[] = [$escrow, -$payment->amount];
        }

        $fees = $order->buyer_fee_amount + $order->addon_fee_amount + $order->method_fee_amount;
        $lines[] = [$escrow, $fees];
        $lines[] = [AccountRef::platform(AccountCode::RevBuyerFee, $currency), -$fees];

        if ($fees === 0 && $payments->isEmpty()) {
            return; // nothing moved (impossible with the minimum fee, kept for safety)
        }

        $this->post->handle(new JournalDraft(
            type: JournalType::PaymentCaptured,
            idempotencyKey: "PAYMENT_CAPTURED:order:{$order->id}",
            lines: $lines,
            sourceType: 'order',
            sourceId: $order->id,
            description: "Order {$order->number}",
        ));
    }

    /**
     * REFUND (spec 7.2): escrow gives back the seller-side part, fee revenue the fee part; the buyer is paid
     * from the credits part (BUYER_CREDITS −) and the card part (PSP_CLEARING −), or everything as credits.
     */
    public function refund(Order $order, string $key, int $sellerPart, int $feePart, int $fromCredits, int $fromPsp, ?string $provider, bool $toCredits): void
    {
        $currency = $order->currency;
        $lines = [
            [AccountRef::platform(AccountCode::OrderEscrow, $currency), $sellerPart],
            [AccountRef::platform(AccountCode::RevBuyerFee, $currency), $feePart],
        ];

        if ($toCredits || $provider === null) {
            $lines[] = [AccountRef::user(AccountCode::BuyerCredits, $order->buyer_id, $currency), -($fromCredits + $fromPsp)];
        } else {
            $lines[] = [AccountRef::user(AccountCode::BuyerCredits, $order->buyer_id, $currency), -$fromCredits];
            $lines[] = [AccountRef::pspClearing($provider, $currency), -$fromPsp];
        }

        $this->post->handle(new JournalDraft(
            type: JournalType::Refund,
            idempotencyKey: $key,
            lines: $lines,
            sourceType: 'order',
            sourceId: $order->id,
            description: "Refund order {$order->number}",
        ));
    }

    /** Reverses a LATE_CAPTURE: the money goes back to the card. */
    public function refundLateCapture(Payment $payment, string $key): void
    {
        $this->post->handle(new JournalDraft(
            type: JournalType::Refund,
            idempotencyKey: $key,
            lines: [
                [AccountRef::platform(AccountCode::OrderEscrow, $payment->currency), $payment->amount],
                [AccountRef::pspClearing($payment->provider, $payment->currency), -$payment->amount],
            ],
            sourceType: 'payment',
            sourceId: $payment->id,
            description: 'Refund of a late capture',
        ));
    }

    public function lateCapture(Payment $payment, Order $order): void
    {
        $this->post->handle(new JournalDraft(
            type: JournalType::LateCapture,
            idempotencyKey: "LATE_CAPTURE:payment:{$payment->id}",
            lines: [
                [AccountRef::pspClearing($payment->provider, $payment->currency), $payment->amount],
                [AccountRef::platform(AccountCode::OrderEscrow, $payment->currency), -$payment->amount],
            ],
            sourceType: 'payment',
            sourceId: $payment->id,
            description: "Late capture for closed order {$order->number}",
        ));
    }
}
