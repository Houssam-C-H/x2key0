<?php

declare(strict_types=1);

namespace App\Domains\Points\Actions;

use App\Domains\Orders\Models\Order;
use App\Domains\Points\Enums\PointsAccountCode;
use App\Domains\Points\Enums\PointsJournalType;
use App\Domains\Points\Models\PointsJournal;
use App\Domains\Points\Models\PointsRule;

/**
 * Points for a completed order (spec 7.7): floor(USD spent × amount_per_usd × level multiplier), journal
 * `earn_order` (USER +, ISSUED −), once per order. Reversal on refund takes back exactly what was earned
 * (USER −, ISSUED +) and may take the user below zero (clawback, invariant 4.3 #2).
 * Called when orders complete (M9) and when they are refunded (M8/M11).
 */
final class AwardOrderPoints
{
    public const string RULE = 'order_completed';

    public function __construct(private readonly PostPointsJournal $post) {}

    public function earn(Order $order): ?PointsJournal
    {
        $points = $this->pointsFor($order);

        if ($points <= 0) {
            return null;
        }

        return $this->post->handle(
            PointsJournalType::EarnOrder,
            "earn_order:order:{$order->id}",
            [[PointsAccountCode::User, $order->buyer_id, $points], [PointsAccountCode::Issued, 0, -$points]],
            'order',
            $order->id,
            "Order {$order->number}",
        );
    }

    public function reverse(Order $order): ?PointsJournal
    {
        $earned = PointsJournal::query()->where('idempotency_key', "earn_order:order:{$order->id}")->with('postings')->first();
        if ($earned === null) {
            return null;
        }

        $sum = $earned->postings->where('amount', '>', 0)->sum('amount');
        $points = is_numeric($sum) ? (int) $sum : 0;

        return $this->post->handle(
            PointsJournalType::Reverse,
            "reverse:order:{$order->id}",
            [[PointsAccountCode::User, $order->buyer_id, -$points], [PointsAccountCode::Issued, 0, $points]],
            'order',
            $order->id,
            "Order {$order->number} refunded",
        );
    }

    /** Points the order is worth under the current rule; spent = total paid in USD cents (minus nothing refunded yet). */
    public function pointsFor(Order $order): int
    {
        $rule = PointsRule::query()->where('code', self::RULE)->where('is_enabled', true)->first();

        if ($rule === null || $order->currency !== 'USD' || ($rule->amount_per_usd ?? 0) <= 0) {
            return $rule->fixed ?? 0;
        }

        $multiplierBps = 10_000;
        if ($rule->multiplier_by_level) {
            $order->loadMissing('buyer.level');
            $multiplierBps = $order->buyer->level->points_multiplier_bps ?? 10_000;
        }

        // floor(cents / 100 × per_usd × bps / 10 000), integer only.
        return intdiv($order->total_amount * $rule->amount_per_usd * $multiplierBps, 100 * 10_000) + ($rule->fixed ?? 0);
    }
}
