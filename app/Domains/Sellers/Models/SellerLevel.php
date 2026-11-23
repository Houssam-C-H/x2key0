<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Seller tier (spec 3.3 / 5.3): commission, hold days, reserve, offer limit, auto-approval, ranking boost.
 *
 * @property int $id
 * @property string $code
 * @property array<string, string> $name
 * @property int $stars
 * @property int $sort
 * @property int $commission_bps
 * @property int $hold_days
 * @property int $reserve_bps
 * @property int $max_active_offers
 * @property bool $auto_approve
 * @property int $rank_boost
 * @property int $min_orders_completed
 * @property int $min_completion_rate_bps
 */
final class SellerLevel extends Model
{
    use Auditable;
    use HasTranslations;

    public const string ENTRY = 'new';

    protected $fillable = [
        'code', 'name', 'stars', 'sort', 'commission_bps', 'hold_days', 'reserve_bps',
        'max_active_offers', 'auto_approve', 'rank_boost', 'min_orders_completed', 'min_completion_rate_bps',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'stars' => 'integer',
            'sort' => 'integer',
            'commission_bps' => 'integer',
            'hold_days' => 'integer',
            'reserve_bps' => 'integer',
            'max_active_offers' => 'integer',
            'auto_approve' => 'boolean',
            'rank_boost' => 'integer',
            'min_orders_completed' => 'integer',
            'min_completion_rate_bps' => 'integer',
        ];
    }

    public static function entry(): self
    {
        return self::query()->where('code', self::ENTRY)->first()
            ?? self::query()->orderBy('sort')->firstOrFail();
    }

    /**
     * @return HasMany<SellerProfile, $this>
     */
    public function profiles(): HasMany
    {
        return $this->hasMany(SellerProfile::class);
    }
}
