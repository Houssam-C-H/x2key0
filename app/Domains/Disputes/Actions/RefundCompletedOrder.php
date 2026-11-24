<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Actions;

use App\Domains\Disputes\Exceptions\DisputeException;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Actions\SellerFunds;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Models\LedgerJournal;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Actions\RefundOrder;
use App\Domains\Payments\Models\Refund;
use App\Domains\Payments\Support\PaymentRouter;
use App\Domains\Points\Actions\AwardOrderPoints;

/**
 * Dispute refund after completion (spec 6.1: disputes within `post_completion_dispute_days`; 7.2 REFUND).
 * The order was settled, so the money comes back from the seller and the platform's revenue:
 *
 * REFUND: seller accounts + net share (frozen money of the case → unreleased holds → Available) ·
 * REV_COMMISSION + commission share · REV_BUYER_FEE + fee share · BUYER_CREDITS − credits part ·
 * PSP_CLEARING − card part (or everything as credits).
 *
 * Shares are proportional to the units kept (the last refund takes what is left). The order stays
 * `completed`; its commission and seller net are reduced; points are reversed when nothing is kept.
 * Runs inside the dispute resolution transaction. Returns the money taken from the frozen balance.
 */
final class RefundCompletedOrder
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly SellerFunds $funds,
        private readonly PaymentRouter $router,
        private readonly OrderStateMachine $orders,
        private readonly AwardOrderPoints $points,
    ) {}

    public function handle(Order $order, int $units, bool $toCredits, int $frozenAvailable, ActorType $actor, ?User $by): int
    {
        /** @var Order $order */
        $order = Order::query()->with(['item', 'seller'])->lockForUpdate()->findOrFail($order->id);
        $item = $order->item;
        $kept = $item->quantity - $item->refunded_qty;

        if ($order->status !== OrderStatus::Completed || $units <= 0 || $units > $kept) {
            throw DisputeException::because('dispute.error.refund_units');
        }

        $all = $units === $kept;
        $sellerPart = $item->unit_price_amount * $units;
        $commissionShare = $all ? $order->commission_amount : intdiv($order->commission_amount * $units, $kept);
        $netShare = $sellerPart - $commissionShare;
        $feesLeft = $order->fees() - $order->refunded_fee_amount;
        $feePart = $all ? $feesLeft : min($feesLeft, intdiv($order->fees() * $units, $item->quantity));
        $promoLeft = $order->discount_platform_amount - $order->refunded_discount_platform_amount;
        $promoPart = $all ? $promoLeft : min($promoLeft, intdiv($order->discount_platform_amount * $units, $item->quantity));
        $total = $sellerPart - $promoPart + $feePart;

        if ($total > $order->refundable()) {
            $feePart = max(0, $order->refundable() - $sellerPart + $promoPart);
            $total = $sellerPart - $promoPart + $feePart;
            if ($total > $order->refundable()) {
                throw DisputeException::because('dispute.error.refund_units');
            }
        }

        [$fromCredits, $fromPsp] = RefundOrder::split($order, $total);
        $recovery = $this->funds->recover($order, $netShare, $frozenAvailable);
        $currency = $order->currency;
        $payment = $fromPsp > 0 ? RefundOrder::pspPayment($order) : null;

        $lines = $recovery['lines'];
        if ($commissionShare > 0) {
            $lines[] = [AccountRef::platform(AccountCode::RevCommission, $currency), $commissionShare];
        }
        if ($feePart > 0) {
            $lines[] = [AccountRef::platform(AccountCode::RevBuyerFee, $currency), $feePart];
        }
        if ($promoPart > 0) {
            $lines[] = [AccountRef::platform(AccountCode::ExpPromotions, $currency), -$promoPart];
        }
        if ($toCredits || $payment === null) {
            $lines[] = [AccountRef::user(AccountCode::BuyerCredits, $order->buyer_id, $currency), -($fromCredits + $fromPsp)];
        } else {
            if ($fromCredits > 0) {
                $lines[] = [AccountRef::user(AccountCode::BuyerCredits, $order->buyer_id, $currency), -$fromCredits];
            }
            $lines[] = [AccountRef::pspClearing($payment->provider, $currency), -$fromPsp];
        }

        $seq = LedgerJournal::query()->where('type', JournalType::Refund)->where('source_type', 'order')->where('source_id', $order->id)->count() + 1;
        $key = "REFUND:order:{$order->id}:{$seq}";

        $this->post->handle(new JournalDraft(
            type: JournalType::Refund,
            idempotencyKey: $key,
            lines: $lines,
            sourceType: 'order',
            sourceId: $order->id,
            description: "Refund order {$order->number} after completion",
            createdByType: $by === null ? 'system' : 'staff',
            createdById: $by?->id,
        ));

        if ($payment !== null && ! $toCredits) {
            $result = $this->router->provider($payment->provider)->refund($payment, $fromPsp, $key);
            RefundOrder::row($order, $payment, Refund::ORIGINAL, $fromPsp, 'dispute_refund', $result->status, $result->providerRefundId, $actor, $by?->id);
        }
        $toCreditsAmount = $fromCredits + (($toCredits || $payment === null) ? $fromPsp : 0);
        if ($toCreditsAmount > 0) {
            RefundOrder::row($order, null, Refund::CREDITS, $toCreditsAmount, 'dispute_refund', 'succeeded', null, $actor, $by?->id);
        }

        // Refunded units stop counting as delivered (DB check: delivered + refunded <= quantity).
        $item->forceFill([
            'refunded_qty' => $item->refunded_qty + $units,
            'delivered_qty' => max(0, $item->delivered_qty - $units),
        ])->save();
        $order->forceFill([
            'refunded_credits_amount' => $order->refunded_credits_amount + $fromCredits,
            'refunded_psp_amount' => $order->refunded_psp_amount + $fromPsp,
            'refunded_fee_amount' => $order->refunded_fee_amount + $feePart,
            'refunded_discount_platform_amount' => $order->refunded_discount_platform_amount + $promoPart,
            'commission_amount' => $order->commission_amount - $commissionShare,
            'seller_net_amount' => $order->seller_net_amount - $netShare,
        ])->save();

        $this->orders->note($order, 'dispute_refund', $actor, $by, ['units' => $units, 'amount' => $total, 'refunded' => $item->refunded_qty, 'quantity' => $item->quantity]);

        if ($all) {
            $this->points->reverse($order);
        }

        return $recovery['fromFrozen'];
    }
}
