<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Support;

use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Exceptions\DisputeException;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Shared\Support\Settings;

/**
 * Who may open a dispute on an order, when, and for what (spec 6.1 last paragraph, Part 10):
 *
 * - buyer: `paid` after the delivery deadline, `delivering`, `delivered`, and `completed` within
 *   `post_completion_dispute_days` (accounts with reason `account_recovered`: until the recovery protection
 *   window ends). "Not received" for a delivered order: only after `not_received_contact_hours` (contact the
 *   seller first) and within the next `not_received_report_hours`;
 * - seller: `buyer_unresponsive` while the order is `paid` or `delivering`;
 * - one open dispute per order; evidence required for not_working / already_used / wrong_region /
 *   account_recovered; replacement and redelivery only before completion.
 */
final class DisputeRules
{
    public function __construct(private readonly Settings $settings) {}

    /** The role `$user` would open a dispute with, or null when the order is not theirs. */
    public static function roleOf(Order $order, User $user): ?DisputeRole
    {
        $order->loadMissing('seller');

        return match (true) {
            $order->buyer_id === $user->id => DisputeRole::Buyer,
            $order->seller->user_id === $user->id => DisputeRole::Seller,
            default => null,
        };
    }

    /**
     * Reasons the role may use on this order right now (empty = cannot open).
     *
     * @return list<DisputeReason>
     */
    public function reasons(Order $order, DisputeRole $role): array
    {
        $order->loadMissing('item.offer');

        if (OpenCases::blocks($order->id)) {
            return [];
        }

        if ($role === DisputeRole::Seller) {
            return in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivering], true) ? [DisputeReason::BuyerUnresponsive] : [];
        }

        $isAccount = $order->item->offer->delivery_method === DeliveryMethod::AccountTransfer;
        $all = array_values(array_filter(DisputeReason::cases(), static fn (DisputeReason $r): bool => $r->openableBy(DisputeRole::Buyer)
            && ($r !== DisputeReason::AccountRecovered || $isAccount)));

        return match ($order->status) {
            OrderStatus::Paid => $order->delivery_due_at !== null && $order->delivery_due_at->isPast()
                ? [DisputeReason::NotReceived, DisputeReason::Other]
                : [],
            OrderStatus::Delivering => array_values(array_filter($all, static fn (DisputeReason $r): bool => $r !== DisputeReason::AccountRecovered)),
            OrderStatus::Delivered => array_values(array_filter($all, fn (DisputeReason $r): bool => $r !== DisputeReason::NotReceived || $this->notReceivedOpen($order))),
            OrderStatus::Completed => $this->afterCompletion($order, $all),
            default => [],
        };
    }

    /**
     * What an order page shows about disputes: the latest case (open first) and whether one can be opened.
     *
     * @return array{case: array{href: string, status: string}|null, canOpen: bool, openHref: string}
     */
    public function forOrder(Order $order, DisputeRole $role): array
    {
        $case = Dispute::query()->where('order_id', $order->id)
            ->orderByRaw('case when status in (?, ?, ?, ?) then 0 else 1 end', DisputeStatus::openValues())
            ->latest('id')->first();

        return [
            'case' => $case === null ? null : ['href' => $case->path(), 'status' => $case->status->value],
            'canOpen' => $this->reasons($order, $role) !== [],
            'openHref' => "/orders/{$order->public_id}/disputes/new",
        ];
    }

    /**
     * Outcomes the opener may request on this order.
     *
     * @return list<DisputeOutcome>
     */
    public static function requestable(Order $order): array
    {
        $order->loadMissing('item');

        if ($order->status === OrderStatus::Completed) {
            return [DisputeOutcome::RefundFull, DisputeOutcome::RefundPartial];
        }

        $outcomes = [DisputeOutcome::RefundFull, DisputeOutcome::RefundPartial, DisputeOutcome::Redelivery];
        if ($order->item->delivered_qty > 0) {
            $outcomes[] = DisputeOutcome::Replacement;
        }

        return $outcomes;
    }

    /** Units still kept by the buyer (refundable / replaceable). */
    public static function keptUnits(Order $order): int
    {
        $order->loadMissing('item');

        return $order->item->quantity - $order->item->refunded_qty;
    }

    public function assertCanOpen(Order $order, DisputeRole $role, DisputeReason $reason, DisputeOutcome $resolution, int $qty, int $evidenceCount): void
    {
        if (! in_array($reason, $this->reasons($order, $role), true)) {
            throw DisputeException::because(OpenCases::blocks($order->id) ? 'dispute.error.already_open' : 'dispute.error.not_allowed');
        }

        if (! in_array($resolution, self::requestable($order), true)) {
            throw DisputeException::because('dispute.error.resolution');
        }

        $kept = self::keptUnits($order);
        if ($qty < 1 || $qty > $kept) {
            throw DisputeException::because('dispute.error.qty', ['max' => $kept]);
        }

        if ($reason->needsEvidence() && $evidenceCount === 0) {
            throw DisputeException::because('dispute.error.evidence_required');
        }
    }

    /**
     * Outcome checks for a resolution (agreement or staff decision): replacement needs delivered units,
     * nothing but refunds after completion, and quantities within what the buyer kept.
     */
    public static function assertOutcome(Order $order, DisputeOutcome $outcome, int $qty): void
    {
        $order->loadMissing('item');
        $completed = $order->status === OrderStatus::Completed;

        $ok = match ($outcome) {
            DisputeOutcome::RefundFull => self::keptUnits($order) > 0 && in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivering, OrderStatus::Delivered, OrderStatus::Completed], true),
            DisputeOutcome::RefundPartial => $qty >= 1 && $qty <= self::keptUnits($order) && in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivering, OrderStatus::Delivered, OrderStatus::Completed], true),
            DisputeOutcome::Replacement => ! $completed && $qty >= 1 && $qty <= $order->item->delivered_qty,
            DisputeOutcome::Redelivery => ! $completed && $qty >= 0 && $qty <= $order->item->delivered_qty
                && ($qty > 0 || $order->status !== OrderStatus::Delivered),
            DisputeOutcome::NoRefund, DisputeOutcome::Cancelled => true,
        };

        if (! $ok) {
            throw DisputeException::because('dispute.error.outcome');
        }
    }

    private function notReceivedOpen(Order $order): bool
    {
        if ($order->delivered_at === null) {
            return false;
        }

        $opens = $order->delivered_at->copy()->addHours($this->settings->int('not_received_contact_hours'));
        $closes = $opens->copy()->addHours($this->settings->int('not_received_report_hours'));

        return now()->between($opens, $closes);
    }

    /**
     * @param  list<DisputeReason>  $all
     * @return list<DisputeReason>
     */
    private function afterCompletion(Order $order, array $all): array
    {
        if ($order->completed_at === null) {
            return [];
        }

        $reasons = [];
        $window = $order->completed_at->copy()->addDays($this->settings->int('post_completion_dispute_days'));

        if ($window->isFuture()) {
            $reasons = array_values(array_filter($all, static fn (DisputeReason $r): bool => ! in_array($r, [DisputeReason::NotReceived, DisputeReason::Partial, DisputeReason::AccountRecovered], true)));
        }

        if ($order->delivered_at !== null && in_array(DisputeReason::AccountRecovered, $all, true)) {
            $days = $order->item->offer->account_recovery_protection_days ?? $this->settings->int('account_recovery_protection_days');
            if ($order->delivered_at->copy()->addDays($days)->isFuture()) {
                $reasons[] = DisputeReason::AccountRecovered;
            }
        }

        return $reasons;
    }
}
