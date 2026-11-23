<?php

declare(strict_types=1);

namespace App\Domains\Orders\Models;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SellerSubstatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only order timeline (spec 5.6 / 6.1, invariant 4.3 #7), written by OrderStateMachine.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property SellerSubstatus|null $from_substatus
 * @property SellerSubstatus|null $to_substatus
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property string|null $reason
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
final class OrderStatusHistory extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'order_status_history';

    protected $fillable = ['order_id', 'from_status', 'to_status', 'from_substatus', 'to_substatus', 'actor_type', 'actor_id', 'reason', 'meta'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Order history is append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Order history is append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'from_substatus' => SellerSubstatus::class,
            'to_substatus' => SellerSubstatus::class,
            'actor_type' => ActorType::class,
            'meta' => 'array',
        ];
    }
}
