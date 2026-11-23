<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Models;

use App\Domains\Ledger\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property AccountType $type
 * @property string $owner_type
 * @property int $owner_id
 * @property string $currency
 * @property Carbon $created_at
 * @property-read LedgerBalance|null $balanceRow
 */
final class LedgerAccount extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => AccountType::class, 'owner_id' => 'integer'];
    }

    /**
     * @return HasOne<LedgerBalance, $this>
     */
    public function balanceRow(): HasOne
    {
        return $this->hasOne(LedgerBalance::class, 'account_id');
    }

    /**
     * @return HasMany<LedgerPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(LedgerPosting::class, 'account_id');
    }

    public function balance(): int
    {
        return $this->balanceRow->balance ?? 0;
    }
}
