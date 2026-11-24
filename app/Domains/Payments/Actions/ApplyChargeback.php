<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Affiliate\Actions\AffiliateCommissions;
use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Actions\SellerFunds;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\ChargebackException;
use App\Domains\Payments\Models\Chargeback;
use App\Domains\Payments\Models\Payment;
use App\Domains\Points\Actions\AwardOrderPoints;
use Illuminate\Support\Facades\DB;

/**
 * Card chargebacks (spec 9.4, 7.2 CHARGEBACK). Called by finance from the admin today and by PSP webhooks
 * once real drivers exist (M6b); idempotent per provider case id.
 *
 * - open: the PSP took the money back → CHARGEBACK: PSP_CLEARING − (amount + fee), EXP_CHARGEBACK_LOSS + amount,
 *   EXP_CHARGEBACK_FEES + fee. The order cannot complete or cancel meanwhile; a completed order's seller money is
 *   frozen (holds stop, released money → SELLER_FROZEN).
 * - won: the money comes back → CHARGEBACK_REVERSAL: PSP_CLEARING + amount, EXP_CHARGEBACK_LOSS − amount (the fee
 *   stays an expense); seller money unfrozen.
 * - lost: the platform recovers what it can → CHARGEBACK_RECOVERY: completed order → from the seller (frozen →
 *   holds → Available, up to the seller net); unfinished order → the escrow of the kept units leaves (up to the
 *   charged-back amount against the loss, the rest to the buyer's credits) and the order closes as `refunded`.
 *   The rest stays a chargeback loss.
 */
final class ApplyChargeback
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly SellerFunds $funds,
        private readonly OrderStateMachine $orders,
        private readonly AwardOrderPoints $points,
        private readonly AffiliateCommissions $affiliates,
    ) {}

    public function open(Payment $payment, string $caseId, int $amount, int $fee = 0, ?string $reasonCode = null): Chargeback
    {
        $existing = Chargeback::query()->where('provider', $payment->provider)->where('provider_case_id', $caseId)->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($payment->status !== PaymentStatus::Captured || $payment->provider === Payment::CREDITS || $amount <= 0 || $amount > $payment->amount || $fee < 0) {
            throw ChargebackException::because('chargeback.error.invalid');
        }

        $chargeback = DB::transaction(function () use ($payment, $caseId, $amount, $fee, $reasonCode): Chargeback {
            /** @var Order $order */
            $order = Order::query()->with('seller')->lockForUpdate()->findOrFail($payment->order_id);

            $chargeback = new Chargeback;
            $chargeback->forceFill([
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'provider_case_id' => mb_substr($caseId, 0, 191),
                'amount' => $amount,
                'fee_amount' => $fee,
                'currency' => $payment->currency,
                'reason_code' => $reasonCode === null ? null : mb_substr($reasonCode, 0, 64),
                'status' => Chargeback::OPEN,
                'opened_at' => now(),
            ])->save();

            $currency = $payment->currency;
            $lines = [
                [AccountRef::pspClearing($payment->provider, $currency), -($amount + $fee)],
                [AccountRef::platform(AccountCode::ExpChargebackLoss, $currency), $amount],
            ];
            if ($fee > 0) {
                $lines[] = [AccountRef::platform(AccountCode::ExpChargebackFees, $currency), $fee];
            }

            $this->post->handle(new JournalDraft(
                type: JournalType::ChargebackOpened,
                idempotencyKey: "CHARGEBACK:chargeback:{$chargeback->id}",
                lines: $lines,
                sourceType: 'chargeback',
                sourceId: $chargeback->id,
                description: "Chargeback on order {$order->number}",
            ));

            if ($order->status === OrderStatus::Completed) {
                $chargeback->forceFill(['frozen_amount' => $this->funds->freeze($order, "chargeback:{$chargeback->id}")])->save();
            }

            $this->orders->note($order, 'chargeback_opened', ActorType::System, null, ['amount' => $amount, 'case' => $chargeback->public_id]);

            return $chargeback->refresh();
        });

        $this->notifySeller($chargeback, 'chargeback_opened');

        return $chargeback;
    }

    public function won(Chargeback $chargeback, ?User $staff): Chargeback
    {
        $done = DB::transaction(function () use ($chargeback, $staff): Chargeback {
            $locked = $this->lockOpen($chargeback);
            /** @var Order $order */
            $order = Order::query()->with('seller')->lockForUpdate()->findOrFail($locked->order_id);
            $currency = $locked->currency;

            $this->post->handle(new JournalDraft(
                type: JournalType::ChargebackReversed,
                idempotencyKey: "CHARGEBACK_REVERSAL:chargeback:{$locked->id}",
                lines: [
                    [AccountRef::pspClearing($locked->provider, $currency), $locked->amount],
                    [AccountRef::platform(AccountCode::ExpChargebackLoss, $currency), -$locked->amount],
                ],
                sourceType: 'chargeback',
                sourceId: $locked->id,
                description: "Chargeback won on order {$order->number}",
                createdByType: $staff === null ? 'system' : 'staff',
                createdById: $staff?->id,
            ));

            $locked->forceFill(['status' => Chargeback::WON, 'closed_at' => now(), 'closed_by' => $staff?->id])->save();
            $this->funds->unfreeze($order, "chargeback:{$locked->id}", $locked->frozen_amount);
            $this->orders->note($order, 'chargeback_won', $staff === null ? ActorType::System : ActorType::Staff, $staff);

            return $locked->refresh();
        });

        $this->notifySeller($done, 'chargeback_won');

        return $done;
    }

    public function lost(Chargeback $chargeback, ?User $staff): Chargeback
    {
        $done = DB::transaction(function () use ($chargeback, $staff): Chargeback {
            $locked = $this->lockOpen($chargeback);
            /** @var Order $order */
            $order = Order::query()->with(['item', 'seller'])->lockForUpdate()->findOrFail($locked->order_id);
            $currency = $locked->currency;
            $case = "chargeback:{$locked->id}";
            $actor = $staff === null ? ActorType::System : ActorType::Staff;

            // The case closes first so the order's open-case guard lets the closing transition through.
            $locked->forceFill(['status' => Chargeback::LOST, 'closed_at' => now(), 'closed_by' => $staff?->id])->save();

            $lines = [];
            $recovered = 0;
            $fromFrozen = 0;

            if ($order->status === OrderStatus::Completed) {
                $recovered = min($locked->amount, $order->seller_net_amount);
                if ($recovered > 0) {
                    $recovery = $this->funds->recover($order, $recovered, $locked->frozen_amount);
                    $lines = $recovery['lines'];
                    $fromFrozen = $recovery['fromFrozen'];
                }
                $order->forceFill(['seller_net_amount' => $order->seller_net_amount - $recovered])->save();
                $this->points->reverse($order);
                $this->affiliates->reverseAll($order, 'chargeback');
            } elseif (in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivering, OrderStatus::Delivered], true)) {
                // Escrow holds the seller-side price of the kept units; all of it leaves with the closed order:
                // the charged-back part covers the loss, anything above it (credits part) goes back to the buyer.
                $kept = $order->item->unit_price_amount * ($order->item->quantity - $order->item->refunded_qty);
                $promoLeft = $order->discount_platform_amount - $order->refunded_discount_platform_amount;
                $recovered = min($locked->amount, $kept - $promoLeft);
                if ($kept > 0) {
                    $lines[] = [AccountRef::platform(AccountCode::OrderEscrow, $currency), $kept];
                }
                if ($promoLeft > 0) {
                    $lines[] = [AccountRef::platform(AccountCode::ExpPromotions, $currency), -$promoLeft];
                }
                if ($kept - $promoLeft > $recovered) {
                    $lines[] = [AccountRef::user(AccountCode::BuyerCredits, $order->buyer_id, $currency), -($kept - $promoLeft - $recovered)];
                }
            }

            if ($lines !== []) {
                $lines[] = [AccountRef::platform(AccountCode::ExpChargebackLoss, $currency), -$recovered];
                $this->post->handle(new JournalDraft(
                    type: JournalType::ChargebackRecovered,
                    idempotencyKey: "CHARGEBACK_RECOVERY:chargeback:{$locked->id}",
                    lines: $lines,
                    sourceType: 'chargeback',
                    sourceId: $locked->id,
                    description: "Chargeback lost on order {$order->number}",
                    createdByType: $staff === null ? 'system' : 'staff',
                    createdById: $staff?->id,
                ));
            }

            $locked->forceFill(['recovered_amount' => $recovered])->save();
            $this->funds->unfreeze($order, $case, $locked->frozen_amount - $fromFrozen);

            if (in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivering, OrderStatus::Delivered], true)) {
                $this->orders->closeByChargeback($order, $actor, $staff);
            } else {
                $this->orders->note($order, 'chargeback_lost', $actor, $staff, ['recovered' => $recovered]);
            }

            return $locked->refresh();
        });

        $this->notifySeller($done, 'chargeback_lost');

        return $done;
    }

    private function lockOpen(Chargeback $chargeback): Chargeback
    {
        /** @var Chargeback $locked */
        $locked = Chargeback::query()->lockForUpdate()->findOrFail($chargeback->id);

        if ($locked->status !== Chargeback::OPEN) {
            throw ChargebackException::because('chargeback.error.closed');
        }

        return $locked;
    }

    private function notifySeller(Chargeback $chargeback, string $type): void
    {
        $chargeback->loadMissing('order.seller');
        $userId = $chargeback->order->seller->user_id;
        $data = ['number' => $chargeback->order->number, 'href' => "/seller/orders/{$chargeback->order->public_id}"];

        DB::afterCommit(fn () => Notifier::notify($userId, $type, 'merchant', $data));
    }
}
