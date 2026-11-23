<?php

declare(strict_types=1);

namespace App\Domains\Coupons\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A buyer's copy of a coupon (spec 5.14): available until used on an order, expired or revoked. A cancelled or fully
 * refunded order gives it back while it has not expired (spec 14.1).
 *
 * @property int $id
 * @property string $public_id
 * @property int $coupon_id
 * @property int $user_id
 * @property Carbon $claimed_at
 * @property Carbon|null $expires_at
 * @property string $status
 * @property int|null $used_order_id
 * @property string $source
 * @property-read Coupon $coupon
 * @property-read User $user
 */
final class CouponClaim extends Model
{
    use HasUlids;

    public const string AVAILABLE = 'available';

    public const string USED = 'used';

    public const string EXPIRED = 'expired';

    public const string REVOKED = 'revoked';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->status === self::AVAILABLE && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
