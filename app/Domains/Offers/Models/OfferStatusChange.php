<?php

declare(strict_types=1);

namespace App\Domains\Offers\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only history row written by OfferStateMachine for every transition (invariant 4.3 #7).
 *
 * @property int $id
 * @property int $offer_id
 * @property OfferStatus|null $from_status
 * @property OfferStatus $to_status
 * @property int|null $actor_id
 * @property string|null $reason
 * @property Carbon $created_at
 * @property-read User|null $actor
 */
final class OfferStatusChange extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = ['offer_id', 'from_status', 'to_status', 'actor_id', 'reason'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Offer status history is append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Offer status history is append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OfferStatus::class,
            'to_status' => OfferStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
