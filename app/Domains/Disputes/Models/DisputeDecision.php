<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Models;

use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * How a dispute ended (spec 5.10): by staff (`decided_by`) or by agreement of the parties (null). One per
 * dispute, never changed.
 *
 * @property int $id
 * @property int $dispute_id
 * @property int|null $decided_by
 * @property DisputeOutcome $outcome
 * @property int $refund_qty
 * @property int $refund_amount
 * @property string|null $refund_destination
 * @property int $redeliver_qty
 * @property string|null $rationale
 * @property string|null $internal_notes
 * @property bool $seller_fault
 * @property Carbon $created_at
 * @property-read User|null $decider
 * @property-read Dispute $dispute
 */
final class DisputeDecision extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['internal_notes'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Dispute decisions are final.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Dispute decisions are final.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => DisputeOutcome::class,
            'refund_qty' => 'integer',
            'refund_amount' => 'integer',
            'redeliver_qty' => 'integer',
            'seller_fault' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Dispute, $this>
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
