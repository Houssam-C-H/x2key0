<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Models;

use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of a dispute thread. `body` is what the author typed (kept for staff); parties see
 * `body_redacted ?? body`. System lines store a translation key and params in `meta`. Internal staff
 * notes (`is_internal`) never reach the parties. Append-only.
 *
 * @property int $id
 * @property int $dispute_id
 * @property int|null $author_id
 * @property DisputeRole $author_role
 * @property string $body
 * @property string|null $body_redacted
 * @property array<string, mixed>|null $meta
 * @property bool $is_internal
 * @property Carbon $created_at
 * @property-read User|null $author
 */
final class DisputeMessage extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Dispute messages are append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Dispute messages are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_role' => DisputeRole::class,
            'meta' => 'array',
            'is_internal' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
