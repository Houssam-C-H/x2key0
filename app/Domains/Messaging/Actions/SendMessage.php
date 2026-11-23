<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Models\Conversation;
use App\Domains\Messaging\Models\Message;
use App\Domains\Messaging\Models\Participant;
use App\Domains\Risk\Models\RiskFlag;
use App\Domains\Sellers\Models\SellerProfile;
use App\Shared\Support\ContactInfo;
use Illuminate\Support\Facades\DB;

/**
 * Posts a chat message (spec 12.1). Contact details are hidden from the counterparty (`body_redacted`),
 * the message is flagged, and a user who keeps doing it raises a risk flag. The seller's auto-reply
 * (first message / off hours, Store Management) answers a buyer's message.
 */
final class SendMessage
{
    public const int MAX_LENGTH = 2000;

    /** Redacted messages by one user within a day before staff are told. */
    private const int REPEAT_THRESHOLD = 3;

    /** Off-hours replies are not repeated inside one conversation more often than this. */
    private const int OFF_HOURS_REPEAT_HOURS = 12;

    public function handle(Conversation $conversation, User $sender, string $body): Message
    {
        $body = trim($body);
        abort_if($body === '' || mb_strlen($body) > self::MAX_LENGTH, 422);
        abort_unless(Participant::query()->where('conversation_id', $conversation->id)->where('user_id', $sender->id)->exists(), 403);

        return DB::transaction(function () use ($conversation, $sender, $body): Message {
            $isBuyer = $sender->id === $conversation->buyer_id;
            $redacted = ContactInfo::redact($body);
            $hidden = $redacted !== $body;

            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'sender_role' => $isBuyer ? 'buyer' : 'seller',
                'kind' => 'text',
                'body' => $body,
                'body_redacted' => $hidden ? $redacted : null,
                'flags' => $hidden ? ['contact_info' => true] : null,
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();
            Participant::query()->where('conversation_id', $conversation->id)->where('user_id', $sender->id)->update(['last_read_message_id' => $message->id]);

            if ($hidden) {
                $this->escalate($conversation, $sender);
            }

            if ($isBuyer) {
                $this->autoReply($conversation);
            }

            return $message;
        });
    }

    private function escalate(Conversation $conversation, User $sender): void
    {
        $recent = Message::query()->where('sender_id', $sender->id)->whereNotNull('body_redacted')->where('created_at', '>=', now()->subDay())->count();
        if ($recent < self::REPEAT_THRESHOLD) {
            return;
        }

        $already = RiskFlag::query()->where('subject_type', 'user')->where('subject_id', $sender->id)->where('rule', 'chat_contact_info')
            ->where('status', 'open')->where('created_at', '>=', now()->subDay())->exists();
        if (! $already) {
            RiskFlag::raise('user', $sender->id, 'chat_contact_info', 20, ['conversation' => $conversation->public_id, 'messages' => $recent]);
        }
    }

    private function autoReply(Conversation $conversation): void
    {
        $seller = $conversation->seller_profile_id === null ? null : SellerProfile::query()->find($conversation->seller_profile_id);
        $config = $seller?->auto_reply;
        if ($seller === null || ! is_array($config)) {
            return;
        }

        $sellerSpoke = Message::query()->where('conversation_id', $conversation->id)->where('sender_role', 'seller')->exists();
        $text = null;
        $kind = 'auto_reply';

        if (! $sellerSpoke && ($config['first_message'] ?? null) !== null) {
            $text = $config['first_message'];
        } elseif (! self::withinHours($config['hours_start'] ?? null, $config['hours_end'] ?? null) && ($config['off_hours_message'] ?? null) !== null) {
            $recentlySent = Message::query()->where('conversation_id', $conversation->id)->where('kind', 'auto_hours')
                ->where('created_at', '>=', now()->subHours(self::OFF_HOURS_REPEAT_HOURS))->exists();
            $text = $recentlySent ? null : $config['off_hours_message'];
            $kind = 'auto_hours';
        }

        if ($text === null) {
            return;
        }

        $reply = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $seller->user_id,
            'sender_role' => 'seller',
            'kind' => $kind,
            'body' => $text,
            'flags' => ['auto_reply' => true],
        ]);
        $conversation->forceFill(['last_message_at' => $reply->created_at])->save();
    }

    /** Working hours are in the platform time zone; a window like 22:00–06:00 wraps past midnight. */
    public static function withinHours(?string $start, ?string $end): bool
    {
        if ($start === null || $end === null) {
            return true;
        }

        $now = now()->format('H:i');

        return $start <= $end ? ($now >= $start && $now <= $end) : ($now >= $start || $now <= $end);
    }
}
