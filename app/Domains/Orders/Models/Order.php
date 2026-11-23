<?php

declare(strict_types=1);

namespace App\Domains\Orders\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SellerSubstatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\Refund;
use App\Domains\Risk\Enums\RiskDecision;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Vault\SealedSecret;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An order of one offer (spec 5.6; multi-item carts are post-launch). Amounts are integer minor units of
 * `currency`. `status` / `seller_substatus` change only through OrderStateMachine; nothing here is mass
 * assignable — CreateOrder fills it explicitly.
 *
 * @property int $id
 * @property string $public_id
 * @property string $number
 * @property int $buyer_id
 * @property int $seller_profile_id
 * @property string $idempotency_key
 * @property OrderStatus $status
 * @property SellerSubstatus|null $seller_substatus
 * @property string $currency
 * @property int $subtotal_amount
 * @property int $discount_platform_amount
 * @property int $discount_seller_amount
 * @property int $buyer_fee_amount
 * @property int $addon_fee_amount
 * @property int $method_fee_amount
 * @property int $tax_amount
 * @property int $total_amount
 * @property int $paid_psp_amount
 * @property int $paid_credits_amount
 * @property int $refunded_credits_amount
 * @property int $refunded_psp_amount
 * @property int $refunded_fee_amount
 * @property int $commission_bps
 * @property int $commission_amount
 * @property int $seller_net_amount
 * @property string|null $buyer_inputs_ciphertext
 * @property string|null $buyer_inputs_dek_wrapped
 * @property string|null $buyer_country
 * @property string|null $ip
 * @property string|null $device_fingerprint
 * @property int $risk_score
 * @property RiskDecision|null $risk_decision
 * @property Carbon|null $payment_due_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $delivery_due_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $confirm_due_at
 * @property Carbon|null $completed_at
 * @property string|null $seller_internal_remark
 * @property int|null $seller_acquisition_cost_amount
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $buyer
 * @property-read SellerProfile $seller
 * @property-read OrderItem $item
 */
final class Order extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    /** Secrets and seller-private fields never leave through serialisation. */
    protected $hidden = ['buyer_inputs_ciphertext', 'buyer_inputs_dek_wrapped', 'seller_internal_remark', 'seller_acquisition_cost_amount', 'idempotency_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'seller_substatus' => SellerSubstatus::class,
            'risk_decision' => RiskDecision::class,
            'subtotal_amount' => 'integer',
            'discount_platform_amount' => 'integer',
            'discount_seller_amount' => 'integer',
            'buyer_fee_amount' => 'integer',
            'addon_fee_amount' => 'integer',
            'method_fee_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'paid_psp_amount' => 'integer',
            'paid_credits_amount' => 'integer',
            'refunded_credits_amount' => 'integer',
            'refunded_psp_amount' => 'integer',
            'refunded_fee_amount' => 'integer',
            'seller_acquisition_cost_amount' => 'integer',
            'commission_bps' => 'integer',
            'commission_amount' => 'integer',
            'seller_net_amount' => 'integer',
            'risk_score' => 'integer',
            'payment_due_at' => 'datetime',
            'paid_at' => 'datetime',
            'accepted_at' => 'datetime',
            'delivery_due_at' => 'datetime',
            'delivered_at' => 'datetime',
            'confirm_due_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'settle_after' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    /**
     * @return HasOne<OrderItem, $this>
     */
    public function item(): HasOne
    {
        return $this->hasOne(OrderItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** Buyer-paid fees: service charge, category add-on, method surcharge. */
    public function fees(): int
    {
        return $this->buyer_fee_amount + $this->addon_fee_amount + $this->method_fee_amount;
    }

    /** Total paid by the buyer and not refunded yet. */
    public function refundable(): int
    {
        return $this->paid_credits_amount + $this->paid_psp_amount - $this->refunded_credits_amount - $this->refunded_psp_amount;
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * @return HasMany<OrderTimer, $this>
     */
    public function timers(): HasMany
    {
        return $this->hasMany(OrderTimer::class);
    }

    public function sealedBuyerInputs(): ?SealedSecret
    {
        return $this->buyer_inputs_ciphertext === null || $this->buyer_inputs_dek_wrapped === null
            ? null
            : new SealedSecret($this->buyer_inputs_ciphertext, $this->buyer_inputs_dek_wrapped);
    }

    /** Amount the PSP must capture: everything not covered by credits. */
    public function pspDue(): int
    {
        return $this->total_amount - $this->paid_credits_amount;
    }

    public function path(): string
    {
        return "/buy/orders/{$this->public_id}";
    }
}
