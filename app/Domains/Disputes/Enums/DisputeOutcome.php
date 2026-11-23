<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Enums;

/**
 * How a dispute ends (spec 5.10 dispute_decisions.outcome, Part 10 options). The first four can also be
 * requested by the opener or proposed by a party (`requested_resolution` / `proposal_resolution`).
 */
enum DisputeOutcome: string
{
    case RefundFull = 'refund_full';
    case RefundPartial = 'refund_partial';
    case Replacement = 'replacement';
    case Redelivery = 'redelivery';
    case NoRefund = 'no_refund';
    case Cancelled = 'cancelled';

    /** Outcomes a party may request or propose. */
    public function requestable(): bool
    {
        return in_array($this, [self::RefundFull, self::RefundPartial, self::Replacement, self::Redelivery], true);
    }

    public function refunds(): bool
    {
        return $this === self::RefundFull || $this === self::RefundPartial;
    }

    public function redelivers(): bool
    {
        return $this === self::Replacement || $this === self::Redelivery;
    }

    /**
     * @return list<string>
     */
    public static function requestableValues(): array
    {
        return array_values(array_map(static fn (self $o): string => $o->value, array_filter(self::cases(), static fn (self $o): bool => $o->requestable())));
    }
}
