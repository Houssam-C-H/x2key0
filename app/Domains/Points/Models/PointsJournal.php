<?php

declare(strict_types=1);

namespace App\Domains\Points\Models;

use App\Domains\Points\Enums\PointsJournalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only points journal (spec 5.13; triggers on PostgreSQL).
 *
 * @property int $id
 * @property PointsJournalType $type
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $description
 * @property string $idempotency_key
 * @property Carbon $posted_at
 */
final class PointsJournal extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Points journals are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Points journals are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => PointsJournalType::class, 'posted_at' => 'datetime', 'source_id' => 'integer'];
    }

    /**
     * @return HasMany<PointsPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(PointsPosting::class, 'journal_id');
    }
}
