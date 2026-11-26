<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Actions;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Messaging\Models\Message;
use App\Shared\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Staff reading a conversation (docs/ADMIN_PLAN.md item 6): read-only, support / moderators / admins, and every
 * opening is audit-logged with the case it was opened for (order, dispute, ticket or the flagged queue).
 * Staff see what the sender wrote and, when contact details were hidden, what the other party saw.
 */
final class StaffChatAccess
{
    public const array CONTEXTS = ['order', 'dispute', 'ticket', 'flagged'];

    public function __construct(private readonly AuditLogger $audit) {}

    public static function allowed(?User $user): bool
    {
        return $user !== null && ($user->hasStaffRole(StaffRole::Support) || $user->hasStaffRole(StaffRole::Moderator) || $user->hasStaffRole(StaffRole::Admin));
    }

    /**
     * @return Collection<int, Message>
     */
    public function open(User $staff, Conversation $conversation, string $context, ?string $contextId = null): Collection
    {
        if (! self::allowed($staff)) {
            throw new DomainException('Only support, moderators and admins can read conversations.');
        }
        if (! in_array($context, self::CONTEXTS, true)) {
            throw new DomainException('Say which case this conversation is opened for.');
        }

        $this->audit->log('chat.transcript_opened', $conversation, null, ['context' => $context, 'context_id' => $contextId], $staff);

        return Message::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
    }

    public function markReviewed(User $staff, Message $message): Message
    {
        if (! self::allowed($staff)) {
            throw new DomainException('Only support, moderators and admins can review messages.');
        }
        if ($message->reviewed_at !== null) {
            return $message;
        }

        $message->forceFill(['reviewed_at' => now(), 'reviewed_by' => $staff->id])->save();
        $this->audit->log('chat.message_reviewed', $message, null, ['conversation_id' => $message->conversation_id], $staff);

        return $message->refresh();
    }
}
