<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $fingerprint
 * @property string|null $name
 * @property Carbon|null $trusted_at
 * @property Carbon|null $last_seen_at
 * @property Carbon $created_at
 */
final class UserDevice extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = ['user_id', 'fingerprint', 'name', 'trusted_at', 'last_seen_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['trusted_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
