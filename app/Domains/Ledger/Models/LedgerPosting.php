<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One debit (+) or credit (−) line. Append-only.
 *
 * @property int $id
 * @property int $journal_id
 * @property int $account_id
 * @property int $amount
 * @property string $currency
 * @property Carbon $created_at
 * @property-read LedgerJournal $journal
 * @property-read LedgerAccount $account
 */
final class LedgerPosting extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Ledger postings are append-only.'));
        self::deleting(static fn (): never => throw new LogicException('Ledger postings are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<LedgerJournal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(LedgerJournal::class, 'journal_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}
