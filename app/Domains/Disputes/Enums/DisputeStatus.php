<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Enums;

/**
 * Dispute status (spec 6.4). Transitions only through DisputeStateMachine; each writes a history row.
 */
enum DisputeStatus: string
{
    case Open = 'open';
    case AwaitingCounterparty = 'awaiting_counterparty';
    case Negotiating = 'negotiating';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::AwaitingCounterparty, self::Cancelled],
            self::AwaitingCounterparty => [self::Negotiating, self::Escalated, self::Resolved, self::Cancelled],
            self::Negotiating => [self::Resolved, self::Escalated, self::Cancelled],
            self::Escalated => [self::Resolved, self::Cancelled],
            self::Resolved, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function isOpen(): bool
    {
        return $this !== self::Resolved && $this !== self::Cancelled;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Open->value, self::AwaitingCounterparty->value, self::Negotiating->value, self::Escalated->value];
    }
}
