<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Models;

use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A manual ledger adjustment (spec 7.6): finance only, with a reason; amounts at or above
 * `manual_adjustment_dual_approval_amount` wait for a second finance user before they are posted.
 *
 * @property int $id
 * @property string $account_code
 * @property int|null $owner_user_id
 * @property string $offset_code
 * @property int $amount
 * @property string $currency
 * @property string $reason
 * @property string $status
 * @property int $requested_by
 * @property int|null $approved_by
 * @property string|null $rejection_reason
 * @property int|null $journal_id
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 * @property-read User|null $owner
 * @property-read User $requester
 */
final class LedgerAdjustment extends Model
{
    public const string PENDING = 'pending';

    public const string POSTED = 'posted';

    public const string REJECTED = 'rejected';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
