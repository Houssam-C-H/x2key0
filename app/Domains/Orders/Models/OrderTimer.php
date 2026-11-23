<?php

declare(strict_types=1);

namespace App\Domains\Orders\Models;

use App\Domains\Orders\Enums\OrderTimerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One timer per (order, type) (spec 5.6). Jobs pick due, unprocessed timers and mark them processed in the
 * same transaction as their effect, which makes every timer job idempotent.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderTimerType $type
 * @property Carbon $due_at
 * @property Carbon|null $processed_at
 */
final class OrderTimer extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'type', 'due_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrderTimerType::class,
            'due_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
