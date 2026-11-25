<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to delete an account (spec 6.8): blocked (with the reasons), or scheduled after the cool-off, then
 * completed (personal data anonymised) or cancelled.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $reason
 * @property string $status
 * @property list<string>|null $blockers
 * @property list<string> $factors
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property-read User $user
 */
final class AccountDeletionRequest extends Model
{
    public const string BLOCKED = 'blocked';

    public const string SCHEDULED = 'scheduled';

    public const string COMPLETED = 'completed';

    public const string CANCELLED = 'cancelled';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['blockers' => 'array', 'factors' => 'array', 'scheduled_for' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
