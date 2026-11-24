<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Models;

use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A platform marketing campaign sellers apply to with offers (spec 14.2): discount events, flash sales,
 * featured slots, new-game launches. Approved offers carry the campaign badge while it runs.
 *
 * @property int $id
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property string $type
 * @property array<string, mixed>|null $rules
 * @property int $platform_subsidy_bps
 * @property int $max_offers_per_seller
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 */
final class Campaign extends Model
{
    use Auditable;
    use HasTranslations;

    public const array TYPES = ['discount_event', 'flash_sale', 'featured_slot', 'new_game_launch'];

    public const array STATUSES = ['draft', 'active', 'ended'];

    protected $fillable = ['name', 'description', 'type', 'rules', 'platform_subsidy_bps', 'max_offers_per_seller', 'starts_at', 'ends_at', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'rules' => 'array',
            'platform_subsidy_bps' => 'integer',
            'max_offers_per_seller' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Active and not over: sellers can still apply (also before it starts).
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'active')->where('ends_at', '>', now());
    }

    /**
     * Running right now (badges show).
     *
     * @param  Builder<self>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->status === 'active' && $this->ends_at->isFuture();
    }

    /** @return HasMany<CampaignParticipation, $this> */
    public function participations(): HasMany
    {
        return $this->hasMany(CampaignParticipation::class);
    }
}
