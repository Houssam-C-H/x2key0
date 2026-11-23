<?php

declare(strict_types=1);

namespace App\Domains\Tickets\StateMachines;

use App\Domains\Identity\Models\User;
use App\Domains\Tickets\Enums\TicketStatus;
use App\Domains\Tickets\Exceptions\TicketException;
use App\Domains\Tickets\Models\Ticket;
use App\Domains\Tickets\Models\TicketMessage;
use App\Domains\Tickets\Models\TicketStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of tickets.status (spec 6.5). Locks the ticket row, checks the transition, applies the
 * caller's changes, writes a ticket_status_history row and a system line in the thread.
 */
final class TicketStateMachine
{
    /**
     * @param  (callable(Ticket, ?TicketStatus): void)|null  $mutate
     */
    public function transition(Ticket $ticket, TicketStatus $to, string $actor, ?User $by, ?string $reason = null, ?callable $mutate = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $to, $actor, $by, $reason, $mutate): Ticket {
            /** @var Ticket $locked */
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw TicketException::because('ticket.error.transition', ['from' => $from->value, 'to' => $to->value]);
            }

            $locked->status = $to;
            if ($mutate !== null) {
                $mutate($locked, $from);
            }
            $locked->save();

            $this->history($locked, $from, $to, $actor, $by, $reason);

            if ($to === TicketStatus::Closed || $from === TicketStatus::Closed) {
                $this->systemLine($locked, $to === TicketStatus::Closed ? "ticket.line.closed_by_{$actor}" : 'ticket.line.reopened');
            }

            return $locked->refresh();
        });
    }

    public function history(Ticket $ticket, ?TicketStatus $from, TicketStatus $to, string $actor, ?User $by, ?string $reason = null): void
    {
        (new TicketStatusHistory)->forceFill([
            'ticket_id' => $ticket->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actor,
            'actor_id' => $by?->id,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            'created_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, string|int>  $params
     */
    public function systemLine(Ticket $ticket, string $key, array $params = []): void
    {
        (new TicketMessage)->forceFill([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_role' => 'system',
            'body' => $key,
            'meta' => ['key' => $key, 'params' => $params],
            'is_internal' => false,
            'created_at' => now(),
        ])->save();
    }
}
