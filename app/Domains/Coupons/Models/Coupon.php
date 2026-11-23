<?php

declare(strict_types=1);

namespace App\Domains\Coupons\Models;

use App\Domains\Sellers\Models\SellerProfile;
use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A coupon (spec 5.14 / 14.1). Platform coupons are funded by the platform (EXP_PROMOTIONS); store coupons are
 * seller percent codes that lower the seller-side price. Buyers use coupons through claims.
 *
 * @property int $id
 * @property string $public_id
 * @property string $issuer
 * @property int|null $seller_profile_id
 * @property string|null $code
 * @property bool $is_public
 * @property array<string, string> $name
 * @property string $type
 * @property int $value
 * @property int|null $max_discount_amount
 * @property int $min_order_amount
 * @property string $currency
 * @property array{game_ids?: list<int>, category_types?: list<string>, game_category_ids?: list<int>}|null $scope
 * @property int|null $claim_limit_total
 * @property int $claim_limit_per_user
 * @property int $claimed_count
 * @property int $used_count
 * @property int|null $points_cost
 * @property int|null $user_level_min
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property int|null $valid_days_after_claim
 * @property string $status
 * @property bool $stackable
 * @property Carbon $created_at
 * @property-read SellerProfile|null $seller
 */
final class Coupon extends Model
{
    use Auditable;
    use HasTranslations;
    use HasUlids;

    public const string PLATFORM = 'platform';

    public const string SELLER = 'seller';

    public const string FIXED = 'fixed';

    public const string PERCENT = 'percent';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'scope' => 'array',
            'is_public' => 'boolean',
            'stackable' => 'boolean',
            'value' => 'integer',
            'max_discount_amount' => 'integer',
            'min_order_amount' => 'integer',
            'claim_limit_total' => 'integer',
            'claim_limit_per_user' => 'integer',
            'claimed_count' => 'integer',
            'used_count' => 'integer',
            'points_cost' => 'integer',
            'user_level_min' => 'integer',
            'valid_days_after_claim' => 'integer',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Active and inside its validity window.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $now = now();
        $query->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $now));
    }

    public function isLive(): bool
    {
        return $this->status === 'active'
            && ($this->valid_from === null || $this->valid_from->lte(now()))
            && ($this->valid_to === null || $this->valid_to->gt(now()));
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    /**
     * @return HasMany<CouponClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(CouponClaim::class);
    }

    /** "35% OFF" / "$5.00 OFF" label data. */
    public function label(): string
    {
        return $this->type === self::PERCENT
            ? rtrim(rtrim(number_format($this->value / 100, 2), '0'), '.').'%'
            : '$'.number_format($this->value / 100, 2);
    }
}
