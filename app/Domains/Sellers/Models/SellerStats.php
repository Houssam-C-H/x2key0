<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Denormalised seller counters (spec 5.3). Written by order/dispute/review jobs (M8+); rates in bps.
 *
 * @property int $seller_profile_id
 * @property int $orders_completed
 * @property int $orders_cancelled_by_seller
 * @property int $disputes_opened
 * @property int $disputes_lost
 * @property int|null $completion_rate_bps
 * @property int|null $positive_rate_bps
 * @property int $rating_count
 * @property int|null $avg_delivery_minutes
 * @property int $sales_30d_amount
 * @property int $violation_points
 * @property Carbon|null $updated_at
 */
final class SellerStats extends Model
{
    public const null CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'seller_profile_id';

    protected $table = 'seller_stats';

    protected $fillable = [
        'seller_profile_id', 'orders_completed', 'orders_cancelled_by_seller', 'disputes_opened', 'disputes_lost',
        'completion_rate_bps', 'positive_rate_bps', 'rating_count', 'avg_delivery_minutes', 'sales_30d_amount', 'violation_points',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orders_completed' => 'integer',
            'orders_cancelled_by_seller' => 'integer',
            'disputes_opened' => 'integer',
            'disputes_lost' => 'integer',
            'completion_rate_bps' => 'integer',
            'positive_rate_bps' => 'integer',
            'rating_count' => 'integer',
            'avg_delivery_minutes' => 'integer',
            'sales_30d_amount' => 'integer',
            'violation_points' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }
}
