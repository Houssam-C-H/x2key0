<?php

declare(strict_types=1);

namespace App\Domains\Points\Models;

use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * How points are earned (spec 5.13): per USD spent and/or fixed, optionally × the buyer level multiplier.
 *
 * @property int $id
 * @property string $code
 * @property string $trigger
 * @property int|null $amount_per_usd
 * @property int|null $fixed
 * @property bool $multiplier_by_level
 * @property bool $is_enabled
 */
final class PointsRule extends Model
{
    use Auditable;

    public const array TRIGGERS = ['order_completed', 'first_order', 'comment', 'daily_login', 'affiliate_signup'];

    protected $fillable = ['code', 'trigger', 'amount_per_usd', 'fixed', 'multiplier_by_level', 'is_enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_per_usd' => 'integer',
            'fixed' => 'integer',
            'multiplier_by_level' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
