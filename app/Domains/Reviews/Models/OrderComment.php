<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Sellers\Models\SellerProfile;
use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A buyer's comment on one completed order (spec 5.11, Part 13). Written only through LeaveComment, replied to
 * through ReplyToComment, moderated through ModerateComment.
 *
 * @property int $id
 * @property int $order_id
 * @property int $buyer_id
 * @property int $seller_profile_id
 * @property int|null $product_id
 * @property int|null $offer_id
 * @property string $rating
 * @property int|null $score_delivery
 * @property int|null $score_service
 * @property int|null $score_description
 * @property string|null $body
 * @property string $status
 * @property string|null $hidden_reason
 * @property int|null $hidden_by
 * @property string|null $seller_reply
 * @property Carbon|null $replied_at
 * @property bool $featured_in_community
 * @property Carbon|null $edited_at
 * @property Carbon $created_at
 * @property-read Order $order
 * @property-read User $buyer
 * @property-read SellerProfile $seller
 */
final class OrderComment extends Model
{
    use Auditable;

    public const array RATINGS = ['positive', 'neutral', 'negative'];

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score_delivery' => 'integer',
            'score_service' => 'integer',
            'score_description' => 'integer',
            'featured_in_community' => 'boolean',
            'replied_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('order_comments.status', 'visible');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** @return BelongsTo<SellerProfile, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }
}
