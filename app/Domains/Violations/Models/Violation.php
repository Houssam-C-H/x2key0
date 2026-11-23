<?php

declare(strict_types=1);

namespace App\Domains\Violations\Models;

use App\Domains\Disputes\Models\Dispute;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Models\Order;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Violations\Enums\ViolationStatus;
use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A violation of a store (spec 5.17 / 6.7). Status changes through Violations\Actions only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $seller_profile_id
 * @property int $violation_rule_id
 * @property int|null $order_id
 * @property int|null $offer_id
 * @property int|null $dispute_id
 * @property int $points
 * @property string|null $details
 * @property ViolationStatus $status
 * @property string|null $appeal_text
 * @property Carbon|null $appealed_at
 * @property int|null $appeal_decided_by
 * @property string|null $decision_note
 * @property Carbon|null $decided_at
 * @property Carbon $appeal_due_at
 * @property Carbon $expires_at
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ViolationRule $rule
 * @property-read SellerProfile $seller
 * @property-read Order|null $order
 * @property-read Offer|null $offer
 * @property-read Dispute|null $dispute
 */
final class Violation extends Model
{
    use Auditable;
    use HasUlids;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ViolationStatus::class,
            'points' => 'integer',
            'appealed_at' => 'datetime',
            'decided_at' => 'datetime',
            'appeal_due_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * Upheld and not expired: counts towards penalties (spec 17.2, points expire after `violation_points_ttl_days`).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ViolationStatus::Upheld->value)->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<ViolationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ViolationRule::class, 'violation_rule_id');
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<Dispute, $this>
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function canAppeal(): bool
    {
        return $this->status === ViolationStatus::Open && $this->appeal_due_at->isFuture();
    }
}
