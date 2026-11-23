<?php

declare(strict_types=1);

namespace App\Domains\Payouts\Models;

use App\Domains\Identity\Models\User;
use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's payout destination (spec 5.16). Details are encrypted at rest and never serialised; pages and
 * withdrawals show the masked label only.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $payout_currency
 * @property array<string, string> $details_encrypted
 * @property string $masked_label
 * @property Carbon|null $verified_at
 * @property bool $is_default
 * @property Carbon|null $disabled_at
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read PayoutMethodType|null $catalog
 */
final class PayoutMethod extends Model
{
    use Auditable;

    protected $guarded = ['*'];

    protected $hidden = ['details_encrypted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details_encrypted' => 'encrypted:array',
            'verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('disabled_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PayoutMethodType, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(PayoutMethodType::class, 'type', 'code');
    }
}
