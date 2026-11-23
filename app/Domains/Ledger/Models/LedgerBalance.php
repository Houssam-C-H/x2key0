<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Projection of an account's postings; written only by PostJournal under a row lock.
 *
 * @property int $account_id
 * @property int $balance
 * @property Carbon $updated_at
 */
final class LedgerBalance extends Model
{
    public const null CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'account_id';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }
}
