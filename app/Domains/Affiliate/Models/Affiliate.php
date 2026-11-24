<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Models;

use App\Domains\Identity\Models\User;
use App\Shared\Audit\Auditable;
use App\Shared\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An enrolled affiliate (spec 5.15). Rates and the attribution window fall back to the settings when null.
 *
 * @property int $id
 * @property int $user_id
 * @property string $code
 * @property int|null $parent_affiliate_id
 * @property string $status
 * @property int $rank
 * @property int|null $commission_bps
 * @property int|null $level2_bps
 * @property int|null $attribution_days
 * @property int $clicks
 * @property int $signups
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read Affiliate|null $parent
 */
final class Affiliate extends Model
{
    use Auditable;

    public const string ACTIVE = 'active';

    public const string SUSPENDED = 'suspended';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'commission_bps' => 'integer',
            'level2_bps' => 'integer',
            'attribution_days' => 'integer',
            'clicks' => 'integer',
            'signups' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function directBps(): int
    {
        return $this->commission_bps ?? app(Settings::class)->int('affiliate_commission_bps');
    }

    public function level2Bps(): int
    {
        return $this->level2_bps ?? app(Settings::class)->int('affiliate_level2_bps');
    }

    public function attributionDays(): int
    {
        return $this->attribution_days ?? app(Settings::class)->int('affiliate_attribution_days');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_affiliate_id');
    }

    /** @return HasMany<AffiliateLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(AffiliateLink::class);
    }
}
