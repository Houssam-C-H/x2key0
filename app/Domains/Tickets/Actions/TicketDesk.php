<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Disputes\Actions\OpenDispute;
use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Exceptions\DisputeException;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Tickets\Enums\TicketPriority;
use App\Domains\Tickets\Enums\TicketStatus;
use App\Domains\Tickets\Enums\TicketTopic;
use App\Domains\Tickets\Exceptions\TicketException;
use App\Domains\Tickets\Models\Ticket;
use App\Domains\Tickets\Models\TicketAttachment;
use App\Domains\Tickets\Models\TicketMessage;
use App\Domains\Tickets\StateMachines\TicketStateMachine;
use App\Shared\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service tickets (spec Part 11 / 6.5):
 *
 * - open (topic, subject, optional own order, message, attachments) → pending with a first-response target by
 *   priority (high for refund / after-sales / payment questions about an order, low for catalog requests);
 * - staff reply → awaiting_reply (first response recorded); user reply → pending (new target); internal notes
 *   change nothing and never reach the user;
 * - close (user or staff), auto-close after `ticket_autoclose_hours` without a user answer (AutoCloseTickets);
 *   reopen by the user within `ticket_reopen_days`; one CSAT rating (1–5) after close;
 * - staff convert an order ticket into a dispute opened in the user's name (link kept) and close the ticket.
 */
final class TicketDesk
{
    public const int MAX_FILES = 5;

    /** Validation rules for one attachment. */
    public const array FILE_RULES = ['file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp,pdf,txt'];

    public function __construct(
        private readonly TicketStateMachine $machine,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array{topic: TicketTopic, subject: string, body: string, order_id: int|null, form_data: array<string, string>}  $data
     * @param  list<UploadedFile>  $files
     */
    public function open(User $user, array $data, array $files = []): Ticket
    {
        if ($data['order_id'] !== null) {
            $order = Order::query()->with('seller')->find($data['order_id']);
            if ($order === null || ($order->buyer_id !== $user->id && $order->seller->user_id !== $user->id)) {
                throw TicketException::because('ticket.error.order');
            }
        }

        return DB::transaction(function () use ($user, $data, $files): Ticket {
            $priority = $data['topic']->defaultPriority($data['order_id'] !== null);

            $ticket = new Ticket;
            $ticket->forceFill([
                'user_id' => $user->id,
                'topic' => $data['topic'],
                'subject' => mb_substr(trim($data['subject']), 0, 150),
                'form_data' => $data['form_data'] === [] ? null : $data['form_data'],
                'order_id' => $data['order_id'],
                'priority' => $priority,
                'status' => TicketStatus::Pending,
                'first_response_due_at' => $this->slaDue($priority),
                'last_reply_at' => now(),
            ])->save();

            $this->machine->history($ticket, null, TicketStatus::Pending, 'user', $user, $data['topic']->value);
            $this->message($ticket, $user, 'user', $data['body'], false, $files);

            return $ticket->refresh();
        });
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function userReply(Ticket $ticket, User $user, string $body, array $files = []): Ticket
    {
        $this->assertOwner($ticket, $user);

        return DB::transaction(function () use ($ticket, $user, $body, $files): Ticket {
            /** @var Ticket $locked */
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($locked->status === TicketStatus::Closed) {
                throw TicketException::because('ticket.error.closed');
            }

            $this->message($locked, $user, 'user', $body, false, $files);
            $touch = function (Ticket $t): void {
                $t->last_reply_at = now();
                $t->first_response_due_at ??= $this->slaDue($t->priority);
            };

            if ($locked->status === TicketStatus::AwaitingReply) {
                return $this->machine->transition($locked, TicketStatus::Pending, 'user', $user, 'user_reply', function (Ticket $t) use ($touch): void {
                    $t->first_response_due_at = $this->slaDue($t->priority);
                    $touch($t);
                });
            }

            $touch($locked);
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function staffReply(Ticket $ticket, User $staff, string $body, bool $internal = false, array $files = []): Ticket
    {
        $reply = DB::transaction(function () use ($ticket, $staff, $body, $internal, $files): Ticket {
            /** @var Ticket $locked */
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($locked->status === TicketStatus::Closed && ! $internal) {
                throw TicketException::because('ticket.error.closed');
            }

            $this->message($locked, $staff, 'staff', $body, $internal, $files);

            if ($internal) {
                return $locked;
            }

            $answered = function (Ticket $t) use ($staff): void {
                $now = now();
                $t->first_responded_at ??= $now;
                $t->last_staff_reply_at = $now;
                $t->last_reply_at = $now;
                $t->first_response_due_at = null;
                $t->assigned_to ??= $staff->id;
            };

            if ($locked->status === TicketStatus::Pending) {
                return $this->machine->transition($locked, TicketStatus::AwaitingReply, 'staff', $staff, 'staff_reply', $answered);
            }

            $answered($locked);
            $locked->save();

            return $locked->refresh();
        });

        if (! $internal) {
            $userId = $reply->user_id;
            $data = ['subject' => $reply->subject, 'href' => $reply->path()];
            DB::afterCommit(fn () => Notifier::notify($userId, 'ticket_reply', 'platform_service', $data));
        }

        return $reply;
    }

    public function close(Ticket $ticket, string $actor, ?User $by, ?string $reason = null): Ticket
    {
        if ($actor === 'user' && $by !== null) {
            $this->assertOwner($ticket, $by);
        }

        $closed = $this->machine->transition($ticket, TicketStatus::Closed, $actor, $by, $reason, static function (Ticket $t) use ($actor): void {
            $t->closed_at = now();
            $t->closed_by = $actor;
            $t->first_response_due_at = null;
        });

        if ($actor !== 'user') {
            $userId = $closed->user_id;
            $data = ['subject' => $closed->subject, 'href' => $closed->path()];
            DB::afterCommit(fn () => Notifier::notify($userId, 'ticket_closed', 'platform_service', $data));
        }

        return $closed;
    }

    public function reopen(Ticket $ticket, User $user): Ticket
    {
        $this->assertOwner($ticket, $user);
        $days = $this->settings->int('ticket_reopen_days');

        return $this->machine->transition($ticket, TicketStatus::Pending, 'user', $user, 'reopen', function (Ticket $t) use ($days): void {
            if ($t->closed_at === null || $t->closed_at->copy()->addDays($days)->isPast() || $t->dispute_id !== null) {
                throw TicketException::because('ticket.error.reopen', ['days' => $days]);
            }
            $t->closed_at = null;
            $t->closed_by = null;
            $t->satisfaction = null;
            $t->satisfaction_comment = null;
            $t->first_response_due_at = $this->slaDue($t->priority);
            $t->last_reply_at = now();
        });
    }

    public function rate(Ticket $ticket, User $user, int $score, ?string $comment): Ticket
    {
        $this->assertOwner($ticket, $user);

        return DB::transaction(function () use ($ticket, $score, $comment): Ticket {
            /** @var Ticket $locked */
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if ($locked->status !== TicketStatus::Closed || $locked->satisfaction !== null || $score < 1 || $score > 5) {
                throw TicketException::because('ticket.error.rate');
            }

            $comment = $comment === null ? null : trim($comment);
            $locked->forceFill(['satisfaction' => $score, 'satisfaction_comment' => $comment === '' ? null : mb_substr((string) $comment, 0, 500)])->save();

            return $locked->refresh();
        });
    }

    public function assign(Ticket $ticket, ?User $staff): Ticket
    {
        $ticket->forceFill(['assigned_to' => $staff?->id])->save();

        return $ticket->refresh();
    }

    /** Priority change keeps the SLA target in step while the ticket waits for staff. */
    public function prioritise(Ticket $ticket, TicketPriority $priority): Ticket
    {
        $ticket->priority = $priority;
        if ($ticket->status === TicketStatus::Pending && $ticket->first_responded_at === null) {
            $ticket->first_response_due_at = $ticket->created_at->copy()->addHours($this->settings->int($priority->slaKey()));
        }
        $ticket->save();

        return $ticket->refresh();
    }

    /**
     * Order ticket with money impact → dispute (spec Part 11). Opened in the ticket owner's name with the dispute
     * rules of that order (owner must be its buyer or seller and the order disputable now); the ticket is closed
     * with a link to the case.
     */
    public function convertToDispute(Ticket $ticket, User $staff, DisputeReason $reason, DisputeOutcome $resolution, int $qty): Ticket
    {
        $ticket->loadMissing(['order', 'user']);

        if ($ticket->order === null || $ticket->dispute_id !== null || $ticket->status === TicketStatus::Closed) {
            throw TicketException::because('ticket.error.convert');
        }

        return DB::transaction(function () use ($ticket, $staff, $reason, $resolution, $qty): Ticket {
            $first = $ticket->messages()->where('author_role', 'user')->orderBy('id')->value('body');
            $description = trim($ticket->subject."\n\n".(is_string($first) ? $first : ''));

            try {
                $dispute = app(OpenDispute::class)->handle($ticket->order, $ticket->user, [
                    'reason' => $reason,
                    'resolution' => $resolution,
                    'qty' => $qty,
                    'description' => mb_substr(str_pad($description, 10, '.'), 0, 2000),
                    'to_credits' => false,
                ]);
            } catch (DisputeException $e) {
                throw new TicketException($e->getMessage(), previous: $e);
            }

            $ticket->forceFill(['dispute_id' => $dispute->id])->save();
            $this->machine->systemLine($ticket, 'ticket.line.converted', ['href' => $dispute->path()]);

            return $this->close($ticket, 'staff', $staff, 'converted_to_dispute');
        });
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function message(Ticket $ticket, User $author, string $role, string $body, bool $internal, array $files): TicketMessage
    {
        $body = trim($body);
        if ($body === '' && $files === []) {
            throw TicketException::because('ticket.error.empty');
        }
        if (count($files) > self::MAX_FILES) {
            throw TicketException::because('ticket.error.files', ['count' => self::MAX_FILES]);
        }

        $message = new TicketMessage;
        $message->forceFill([
            'ticket_id' => $ticket->id,
            'author_id' => $author->id,
            'author_role' => $role,
            'body' => mb_substr($body, 0, 5000),
            'meta' => null,
            'is_internal' => $internal,
            'created_at' => now(),
        ])->save();

        foreach ($files as $file) {
            (new TicketAttachment)->forceFill([
                'ticket_id' => $ticket->id,
                'ticket_message_id' => $message->id,
                'file_path' => (string) $file->store("tickets/{$ticket->public_id}", 'local'),
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 191),
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'sha256' => (string) hash_file('sha256', $file->getRealPath()),
                'scan_status' => 'pending',
                'created_at' => now(),
            ])->save();
        }

        return $message;
    }

    private function slaDue(TicketPriority $priority): Carbon
    {
        return now()->addHours($this->settings->int($priority->slaKey()));
    }

    private function assertOwner(Ticket $ticket, User $user): void
    {
        if ($ticket->user_id !== $user->id) {
            throw TicketException::because('ticket.error.not_yours');
        }
    }
}
