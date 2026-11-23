<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Models;

use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A case about one order (spec 5.10). Status changes only through DisputeStateMachine.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $order_item_id
 * @property int $opened_by_id
 * @property DisputeRole $opened_by_role
 * @property DisputeReason $reason
 * @property DisputeStatus $status
 * @property DisputeOutcome $requested_resolution
 * @property int $requested_qty
 * @property bool $refund_to_credits
 * @property string $description
 * @property string|null $previous_substatus
 * @property DisputeOutcome|null $proposal_resolution
 * @property int|null $proposal_qty
 * @property DisputeRole|null $proposal_by_role
 * @property int $frozen_amount
 * @property Carbon|null $response_due_at
 * @property Carbon|null $escalated_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 * @property-read OrderItem $item
 * @property-read User $opener
 * @property-read DisputeDecision|null $decision
 */
final class Dispute extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_by_role' => DisputeRole::class,
            'reason' => DisputeReason::class,
            'status' => DisputeStatus::class,
            'requested_resolution' => DisputeOutcome::class,
            'proposal_resolution' => DisputeOutcome::class,
            'proposal_by_role' => DisputeRole::class,
            'requested_qty' => 'integer',
            'proposal_qty' => 'integer',
            'frozen_amount' => 'integer',
            'refund_to_credits' => 'boolean',
            'response_due_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** An open dispute or chargeback blocks completion, cancellation and the release of the order's holds. */
    public static function openFor(int $orderId): ?self
    {
        return self::query()->where('order_id', $orderId)->whereIn('status', DisputeStatus::openValues())->first();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', DisputeStatus::openValues());
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_id');
    }

    /**
     * @return HasMany<DisputeMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class);
    }

    /**
     * @return HasMany<DisputeEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(DisputeEvidence::class);
    }

    /**
     * @return HasMany<DisputeStatusHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(DisputeStatusHistory::class);
    }

    /**
     * @return HasOne<DisputeDecision, $this>
     */
    public function decision(): HasOne
    {
        return $this->hasOne(DisputeDecision::class);
    }

    /** The party who has to answer now (null once escalated or closed). */
    public function counterparty(): ?DisputeRole
    {
        return in_array($this->status, [DisputeStatus::AwaitingCounterparty, DisputeStatus::Negotiating], true)
            ? $this->opened_by_role->other()
            : null;
    }

    public function path(): string
    {
        return "/disputes/{$this->public_id}";
    }
}
