<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Models;

use App\Domains\Disputes\Models\Dispute;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Tickets\Enums\TicketPriority;
use App\Domains\Tickets\Enums\TicketStatus;
use App\Domains\Tickets\Enums\TicketTopic;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A support ticket (spec 5.10). Status changes only through TicketStateMachine.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property TicketTopic $topic
 * @property string $subject
 * @property array<string, string>|null $form_data
 * @property int|null $order_id
 * @property int|null $dispute_id
 * @property TicketPriority $priority
 * @property TicketStatus $status
 * @property int|null $assigned_to
 * @property Carbon|null $first_response_due_at
 * @property Carbon|null $first_responded_at
 * @property Carbon|null $last_reply_at
 * @property Carbon|null $last_staff_reply_at
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 * @property int|null $satisfaction
 * @property string|null $satisfaction_comment
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read User|null $assignee
 * @property-read Order|null $order
 * @property-read Dispute|null $dispute
 */
final class Ticket extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'topic' => TicketTopic::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'form_data' => 'array',
            'satisfaction' => 'integer',
            'first_response_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'last_reply_at' => 'datetime',
            'last_staff_reply_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Dispute, $this>
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /**
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /**
     * @return HasMany<TicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * @return HasMany<TicketStatusHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(TicketStatusHistory::class);
    }

    /** Waiting for staff past the first-response target. */
    public function isOverdue(): bool
    {
        return $this->status === TicketStatus::Pending && $this->first_response_due_at !== null && $this->first_response_due_at->isPast();
    }

    public function path(): string
    {
        return "/tickets/{$this->public_id}";
    }
}
