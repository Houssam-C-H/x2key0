<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Enums;

/**
 * Service ticket status (spec 6.5): pending (staff to reply) ⇄ awaiting_reply (waiting for the user) → closed;
 * closed → pending when the user reopens within `ticket_reopen_days`. Only TicketStateMachine changes it.
 */
enum TicketStatus: string
{
    case Pending = 'pending';
    case AwaitingReply = 'awaiting_reply';
    case Closed = 'closed';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::AwaitingReply, self::Closed],
            self::AwaitingReply => [self::Pending, self::Closed],
            self::Closed => [self::Pending],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
