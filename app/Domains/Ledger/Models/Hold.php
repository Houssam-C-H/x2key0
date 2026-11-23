<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Models;

use App\Domains\Orders\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money withheld until `release_at` (spec 5.12 / 7.5): seller earnings after completion, insurance
 * installments, and the rolling reserve. Released by `holds:release` through PostJournal.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property int|null $order_id
 * @property string $kind
 * @property int $seq
 * @property int $amount
 * @property int $reserve_bps
 * @property string $currency
 * @property Carbon $release_at
 * @property Carbon|null $released_at
 * @property int|null $journal_id
 * @property int|null $source_hold_id
 * @property string|null $frozen_by dispute:{id} | chargeback:{id} — not released while set
 * @property Carbon $created_at
 * @property-read Order|null $order
 */
final class Hold extends Model
{
    public const null UPDATED_AT = null;

    public const string SETTLEMENT = 'settlement';

    public const string INSURANCE = 'insurance';

    public const string RESERVE = 'reserve';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'seq' => 'integer',
            'reserve_bps' => 'integer',
            'release_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->whereNull('released_at')->whereNull('frozen_by')->where('release_at', '<=', now());
    }
}
