<?php

declare(strict_types=1);

namespace App\Domains\Payouts\Models;

use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A payout method offered by the platform (spec 5.16 payout_method_catalog): fee (bps + fixed), minimum,
 * currencies and the detail fields a user fills in. Finance edits fees; codes are seeded.
 *
 * @property string $code
 * @property string $name
 * @property list<string> $currencies
 * @property list<string> $fields
 * @property int $fee_bps
 * @property int $fee_fixed_amount
 * @property int $min_amount
 * @property int $sort
 * @property bool $is_enabled
 */
final class PayoutMethodType extends Model
{
    use Auditable;

    public $incrementing = false;

    protected $table = 'payout_method_catalog';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'currencies', 'fields', 'fee_bps', 'fee_fixed_amount', 'min_amount', 'sort', 'is_enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'currencies' => 'array',
            'fields' => 'array',
            'fee_bps' => 'integer',
            'fee_fixed_amount' => 'integer',
            'min_amount' => 'integer',
            'sort' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }
}
