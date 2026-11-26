<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * `body` is what the sender wrote (staff and the sender see it); `body_redacted` is what the counterparty
 * sees when contact details were hidden (spec 12.1).
 *
 * @property int $id
 * @property int $conversation_id
 * @property int|null $sender_id
 * @property string $sender_role
 * @property string $kind
 * @property string $body
 * @property string|null $body_redacted
 * @property array<string, mixed>|null $flags
 * @property Carbon $created_at
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property-read Conversation $conversation
 * @property-read User|null $sender
 */
final class Message extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'sender_id', 'sender_role', 'kind', 'body', 'body_redacted', 'flags'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['flags' => 'array', 'reviewed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
