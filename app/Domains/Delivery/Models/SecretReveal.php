<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Every time a buyer shows a delivered secret (spec 8.3): dispute and chargeback evidence. Append-only.
 *
 * @property int $id
 * @property int $order_item_id
 * @property int|null $storage_item_id
 * @property int|null $delivery_secret_id
 * @property int $user_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
final class SecretReveal extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Reveal logs are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Reveal logs are append-only.'));
    }
}
