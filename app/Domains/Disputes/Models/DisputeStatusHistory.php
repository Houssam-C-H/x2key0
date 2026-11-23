<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Models;

use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only row written by DisputeStateMachine for every transition.
 *
 * @property int $id
 * @property int $dispute_id
 * @property DisputeStatus|null $from_status
 * @property DisputeStatus $to_status
 * @property DisputeRole $actor_type
 * @property int|null $actor_id
 * @property string|null $reason
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
final class DisputeStatusHistory extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'dispute_status_history';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Dispute history is append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Dispute history is append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => DisputeStatus::class,
            'to_status' => DisputeStatus::class,
            'actor_type' => DisputeRole::class,
            'meta' => 'array',
        ];
    }
}
