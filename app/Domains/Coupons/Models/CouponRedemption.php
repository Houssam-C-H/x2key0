<?php

declare(strict_types=1);

namespace App\Domains\Coupons\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A claim spent on an order (spec 5.6 order_coupon_redemptions): unique per claim, reversed at most once.
 *
 * @property int $id
 * @property int $order_id
 * @property int $coupon_claim_id
 * @property int $amount
 * @property string $funded_by
 * @property Carbon|null $reversed_at
 * @property Carbon $created_at
 * @property-read CouponClaim $claim
 */
final class CouponRedemption extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'order_coupon_redemptions';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'reversed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<CouponClaim, $this>
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(CouponClaim::class, 'coupon_claim_id');
    }
}
