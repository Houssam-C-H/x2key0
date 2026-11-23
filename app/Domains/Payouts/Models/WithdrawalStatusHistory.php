<?php

declare(strict_types=1);

namespace App\Domains\Payouts\Models;

use App\Domains\Payouts\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only row written by WithdrawalStateMachine for every transition.
 *
 * @property int $id
 * @property int $withdrawal_request_id
 * @property WithdrawalStatus|null $from_status
 * @property WithdrawalStatus $to_status
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
final class WithdrawalStatusHistory extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'withdrawal_status_history';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Withdrawal history is append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Withdrawal history is append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['from_status' => WithdrawalStatus::class, 'to_status' => WithdrawalStatus::class];
    }
}
