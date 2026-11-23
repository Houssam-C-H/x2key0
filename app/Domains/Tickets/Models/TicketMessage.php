<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of a ticket thread. Internal notes never reach the user. System lines keep a translation key and
 * params in `meta`. Append-only.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $author_id
 * @property string $author_role
 * @property string $body
 * @property array<string, mixed>|null $meta
 * @property bool $is_internal
 * @property Carbon $created_at
 * @property-read User|null $author
 */
final class TicketMessage extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Ticket messages are append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Ticket messages are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meta' => 'array', 'is_internal' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return HasMany<TicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }
}
