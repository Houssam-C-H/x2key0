<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Models;

use App\Domains\Ledger\Enums\JournalType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only (spec 5.12; also enforced by triggers on PostgreSQL).
 *
 * @property int $id
 * @property string $public_id
 * @property JournalType $type
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $description
 * @property string $created_by_type
 * @property int|null $created_by_id
 * @property string $idempotency_key
 * @property Carbon $posted_at
 */
final class LedgerJournal extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Ledger journals are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Ledger journals are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => JournalType::class, 'posted_at' => 'datetime', 'source_id' => 'integer'];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return HasMany<LedgerPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(LedgerPosting::class, 'journal_id');
    }
}
