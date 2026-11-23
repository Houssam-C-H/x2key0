<?php

declare(strict_types=1);

namespace App\Domains\Points\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $journal_id
 * @property int $account_id
 * @property int $amount
 * @property Carbon $created_at
 */
final class PointsPosting extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Points postings are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Points postings are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
