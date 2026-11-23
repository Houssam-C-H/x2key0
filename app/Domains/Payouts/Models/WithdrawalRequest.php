<?php

declare(strict_types=1);

namespace App\Domains\Payouts\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Payouts\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A withdrawal (spec 5.16 / 6.6). Status changes only through WithdrawalStateMachine; money only through
 * its WITHDRAWAL_* journals.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $source
 * @property int $amount
 * @property string $currency
 * @property int $fee_amount
 * @property int $payout_amount
 * @property string $payout_currency
 * @property int $payout_method_id
 * @property string $payout_label
 * @property WithdrawalStatus $status
 * @property string|null $remarks
 * @property string|null $failure_reason
 * @property int|null $reviewed_by
 * @property string|null $provider_ref
 * @property string $idempotency_key
 * @property Carbon $requested_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read PayoutMethod $method
 */
final class WithdrawalRequest extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected $hidden = ['idempotency_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'payout_amount' => 'integer',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PayoutMethod, $this>
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PayoutMethod::class, 'payout_method_id');
    }

    /**
     * @return HasMany<WithdrawalStatusHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(WithdrawalStatusHistory::class);
    }

    public function number(): string
    {
        return 'W'.strtoupper(substr($this->public_id, -10));
    }
}
