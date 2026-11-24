<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Models;

use App\Domains\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Commission of one affiliate on one completed order (spec 5.15): pending (AFFILIATE_PENDING) until `release_at`,
 * then approved (AFFILIATE_AVAILABLE); refunds and chargebacks reverse part or all of it (`reversed_amount`).
 *
 * @property int $id
 * @property int $affiliate_id
 * @property int $order_id
 * @property int $level
 * @property int $base_amount
 * @property int $rate_bps
 * @property int $commission_amount
 * @property int $reversed_amount
 * @property string $currency
 * @property string $status
 * @property Carbon $release_at
 * @property Carbon|null $released_at
 * @property Carbon $created_at
 * @property-read Order $order
 * @property-read Affiliate $affiliate
 */
final class AffiliateCommission extends Model
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REVERSED = 'reversed';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'base_amount' => 'integer',
            'rate_bps' => 'integer',
            'commission_amount' => 'integer',
            'reversed_amount' => 'integer',
            'release_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** What the affiliate keeps of it. */
    public function net(): int
    {
        return $this->commission_amount - $this->reversed_amount;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
