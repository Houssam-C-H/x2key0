<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only history row written by SellerProfileStateMachine for every transition.
 *
 * @property int $id
 * @property int $seller_profile_id
 * @property SellerStatus $from_status
 * @property SellerStatus $to_status
 * @property int|null $actor_id
 * @property string|null $reason
 * @property Carbon $created_at
 * @property-read User|null $actor
 */
final class SellerStatusChange extends Model
{
    public const null UPDATED_AT = null;

    protected $fillable = ['seller_profile_id', 'from_status', 'to_status', 'actor_id', 'reason'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Seller status history is append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Seller status history is append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => SellerStatus::class,
            'to_status' => SellerStatus::class,
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
