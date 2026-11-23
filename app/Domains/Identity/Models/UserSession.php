<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registry of a user's sessions, independent of the session driver, so they can be listed and revoked.
 *
 * @property int $id
 * @property int $user_id
 * @property string $session_id
 * @property int|null $user_device_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $last_active_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 */
final class UserSession extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = ['user_id', 'session_id', 'user_device_id', 'ip', 'user_agent', 'last_active_at', 'revoked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_active_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<UserDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'user_device_id');
    }
}
