<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only login history (success and failure).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $identifier
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $device_fingerprint
 * @property bool $succeeded
 * @property Carbon $created_at
 */
final class LoginEvent extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['succeeded' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Login events are append-only.'));
    }
}
