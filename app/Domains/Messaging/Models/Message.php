<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
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
        return ['flags' => 'array'];
    }
}
