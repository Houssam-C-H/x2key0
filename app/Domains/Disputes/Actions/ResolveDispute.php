<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Exceptions\DisputeException;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Models\DisputeDecision;
use App\Domains\Disputes\StateMachines\DisputeStateMachine;
use App\Domains\Disputes\Support\DisputeRules;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\SellerFunds;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SellerSubstatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Risk\Models\RiskFlag;
use App\Domains\Sellers\Models\SellerStats;
use App\Domains\Violations\Actions\RecordViolation;
use Illuminate\Support\Facades\DB;

/**
 * Ends a dispute and carries out its outcome in one transaction (spec Part 10 / 6.4 / 6.1):
 *
 * | outcome        | before completion (escrow)                         | after completion (settled)              |
 * |----------------|----------------------------------------------------|-----------------------------------------|
 * | refund_full    | all kept units refunded → order `refunded`         | REFUND from seller funds + revenue     |
 * | refund_partial | `qty` units refunded, order goes on                | same, for `qty` units                   |
 * | replacement    | `qty` delivered units back to the seller → delivering | —                                     |
 * | redelivery     | new delivery deadline (and `qty` units back)       | —                                       |
 * | no_refund      | order resumes (ISSUE cleared, 24 h to confirm)     | frozen funds and holds released         |
 * | cancelled      | opener withdrew: same as no_refund, status `cancelled` |                                     |
 *
 * Writes the decision row (staff or agreement), the seller's lost-dispute stats and violation when
 * `seller_fault`, and a buyer-abuse risk flag after repeated lost disputes. The case is closed before the
 * order changes, so the order's open-case guard lets the outcome through.
 */
final class ResolveDispute
{
    public function __construct(
        private readonly DisputeStateMachine $machine,
        private readonly OrderStateMachine $orders,
        private readonly RefundCompletedOrder $completedRefund,
        private readonly SellerFunds $funds,
        private readonly RecordViolation $violations,
    ) {}

    /**
     * @param  array{qty?: int, to_credits?: bool|null, rationale?: string|null, internal_notes?: string|null, seller_fault?: bool}  $options
     */
    public function handle(Dispute $dispute, DisputeOutcome $outcome, DisputeRole $actor, ?User $by, array $options = []): Dispute
    {
        $closed = DB::transaction(function () use ($dispute, $outcome, $actor, $by, $options): Dispute {
            /** @var Dispute $locked */
            $locked = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if (! $locked->status->isOpen()) {
                throw DisputeException::because('dispute.error.closed');
            }

            /** @var Order $order */
            $order = Order::query()->with(['item', 'seller'])->lockForUpdate()->findOrFail($locked->order_id);
            $qty = match ($outcome) {
                DisputeOutcome::RefundFull => DisputeRules::keptUnits($order),
                DisputeOutcome::NoRefund, DisputeOutcome::Cancelled => 0,
                default => $options['qty'] ?? $locked->requested_qty,
            };
            if ($outcome === DisputeOutcome::RefundPartial && $qty === DisputeRules::keptUnits($order)) {
                $outcome = DisputeOutcome::RefundFull;
            }
            DisputeRules::assertOutcome($order, $outcome, $qty);

            $toCredits = $options['to_credits'] ?? $locked->refund_to_credits;
            $sellerFault = (bool) ($options['seller_fault'] ?? false);
            $orderActor = match ($actor) {
                DisputeRole::Buyer => ActorType::Buyer,
                DisputeRole::Seller => ActorType::Seller,
                DisputeRole::Staff => ActorType::Staff,
                DisputeRole::System => ActorType::System,
            };
            $paidBefore = $order->refunded_credits_amount + $order->refunded_psp_amount;

            $dispute = $this->machine->transition($locked, $outcome === DisputeOutcome::Cancelled ? DisputeStatus::Cancelled : DisputeStatus::Resolved, $actor, $by, $outcome->value, ['qty' => $qty]);
            $case = "dispute:{$dispute->id}";

            if ($order->status === OrderStatus::Completed) {
                $fromFrozen = $outcome->refunds() ? $this->completedRefund->handle($order, $qty, $toCredits, $dispute->frozen_amount, $orderActor, $by) : 0;
                $this->funds->unfreeze($order, $case, $dispute->frozen_amount - $fromFrozen);
            } else {
                $restore = $dispute->previous_substatus === null ? null : SellerSubstatus::tryFrom($dispute->previous_substatus);

                if ($outcome->refunds()) {
                    $order = $this->orders->disputeRefund($order, $qty, $toCredits, $orderActor, $by);
                } elseif ($outcome->redelivers()) {
                    $order = $this->orders->redeliver($order, $qty, $orderActor, $by);
                }

                if (in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivering, OrderStatus::Delivered], true)) {
                    $this->orders->resumeAfterDispute($order, $restore, $orderActor, $by);
                }
            }

            $order->refresh();
            (new DisputeDecision)->forceFill([
                'dispute_id' => $dispute->id,
                'decided_by' => $actor === DisputeRole::Staff ? $by?->id : null,
                'outcome' => $outcome,
                'refund_qty' => $outcome->refunds() ? $qty : 0,
                'refund_amount' => $order->refunded_credits_amount + $order->refunded_psp_amount - $paidBefore,
                'refund_destination' => $outcome->refunds() ? ($toCredits ? 'credits' : 'original') : null,
                'redeliver_qty' => $outcome->redelivers() ? $qty : 0,
                'rationale' => self::text($options['rationale'] ?? null),
                'internal_notes' => self::text($options['internal_notes'] ?? null),
                'seller_fault' => $sellerFault,
                'created_at' => now(),
            ])->save();

            if ($sellerFault) {
                SellerStats::query()->where('seller_profile_id', $order->seller_profile_id)->increment('disputes_lost');
                $this->violations->auto('dispute_lost', $order->seller_profile_id, orderId: $order->id, disputeId: $dispute->id);
            }

            if ($dispute->opened_by_role === DisputeRole::Buyer && $outcome === DisputeOutcome::NoRefund && $actor === DisputeRole::Staff) {
                self::flagBuyerAbuse($dispute->opened_by_id);
            }

            return $dispute->refresh();
        });

        $closed->loadMissing('order.seller');
        $data = OpenDispute::notice($closed);
        DB::afterCommit(function () use ($closed, $data): void {
            Notifier::notify($closed->order->buyer_id, 'dispute_resolved', 'dispute', $data);
            Notifier::notify($closed->order->seller->user_id, 'dispute_resolved', 'dispute', $data);
        });

        return $closed;
    }

    /** Buyer abuse tracking (spec Part 10): 3+ disputes lost in 90 days → one risk flag per day for staff. */
    private static function flagBuyerAbuse(int $buyerId): void
    {
        $lost = DisputeDecision::query()
            ->where('outcome', DisputeOutcome::NoRefund->value)
            ->whereNotNull('decided_by')
            ->where('created_at', '>=', now()->subDays(90))
            ->whereHas('dispute', fn ($q) => $q->where('opened_by_id', $buyerId)->where('opened_by_role', DisputeRole::Buyer->value))
            ->count();

        $exists = RiskFlag::query()->where('subject_type', 'user')->where('subject_id', $buyerId)->where('rule', 'buyer_dispute_abuse')
            ->where('created_at', '>=', now()->startOfDay())->exists();

        if ($lost >= 3 && ! $exists) {
            (new RiskFlag)->forceFill([
                'subject_type' => 'user',
                'subject_id' => $buyerId,
                'rule' => 'buyer_dispute_abuse',
                'score' => 30,
                'details' => ['lost_disputes_90d' => $lost],
                'status' => 'open',
            ])->save();
        }
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_substr($value, 0, 5000);
    }
}
