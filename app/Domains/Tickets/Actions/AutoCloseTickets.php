<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Tickets\Enums\TicketStatus;
use App\Domains\Tickets\Exceptions\TicketException;
use App\Domains\Tickets\Models\Ticket;
use App\Domains\Tickets\StateMachines\TicketStateMachine;
use App\Shared\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * `tickets:autoclose` (every 15 min, idempotent): a ticket waiting for the user (`awaiting_reply`) whose last staff
 * answer is older than `ticket_autoclose_hours` closes by itself (spec 6.5). Re-checked under the row lock, so a
 * user reply that arrives meanwhile wins.
 */
final class AutoCloseTickets
{
    public function __construct(
        private readonly TicketStateMachine $machine,
        private readonly Settings $settings,
    ) {}

    public function handle(): int
    {
        $cutoff = now()->subHours($this->settings->int('ticket_autoclose_hours'));
        $count = 0;

        Ticket::query()->where('status', TicketStatus::AwaitingReply->value)->where('last_staff_reply_at', '<=', $cutoff)
            ->orderBy('id')->chunkById(200, function ($tickets) use (&$count, $cutoff): void {
                foreach ($tickets as $ticket) {
                    try {
                        $closed = $this->machine->transition($ticket, TicketStatus::Closed, 'system', null, 'autoclose', static function (Ticket $t, ?TicketStatus $from) use ($cutoff): void {
                            if ($from !== TicketStatus::AwaitingReply || ! $t->last_staff_reply_at instanceof Carbon || $t->last_staff_reply_at->gt($cutoff)) {
                                throw TicketException::because('ticket.error.transition', ['from' => $from->value ?? '', 'to' => TicketStatus::Closed->value]);
                            }
                            $t->closed_at = now();
                            $t->closed_by = 'system';
                        });
                    } catch (TicketException) {
                        continue; // the user answered or it closed meanwhile
                    }

                    Notifier::notify($closed->user_id, 'ticket_closed', 'platform_service', ['subject' => $closed->subject, 'href' => $closed->path()]);
                    $count++;
                }
            });

        return $count;
    }
}
