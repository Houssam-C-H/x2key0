<?php

declare(strict_types=1);

namespace App\Domains\Admin\Models;

use App\Domains\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Internal note by a staff member on a user, store or order. Append-only.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $author_id
 * @property string $body
 * @property CarbonImmutable $created_at
 * @property-read User $author
 */
final class StaffNote extends Model
{
    public const null UPDATED_AT = null;

    public const array SUBJECTS = ['user', 'seller', 'order'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Staff notes are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Staff notes are append-only.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
