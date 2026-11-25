<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An external identity (Google, Facebook) that can log a user in (ADR-033).
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string|null $email
 * @property Carbon|null $last_used_at
 * @property Carbon $linked_at
 * @property-read User $user
 */
final class SocialAccount extends Model
{
    use Auditable;

    /** The table has `linked_at` (database default) instead of created/updated timestamps. */
    public $timestamps = false;

    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'email', 'last_used_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'linked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
