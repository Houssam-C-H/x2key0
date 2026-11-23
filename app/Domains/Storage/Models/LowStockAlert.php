<?php

declare(strict_types=1);

namespace App\Domains\Storage\Models;

use App\Domains\Offers\Models\Offer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An auto-delivered offer whose storage group ran low (spec 8.1). At most one open (unresolved) alert per
 * offer; dismissing hides it from the alert bar until it resolves and triggers again.
 *
 * @property int $id
 * @property int $seller_profile_id
 * @property int $offer_id
 * @property int|null $storage_group_id
 * @property Carbon $triggered_at
 * @property Carbon|null $dismissed_at
 * @property Carbon|null $resolved_at
 * @property-read Offer $offer
 */
final class LowStockAlert extends Model
{
    public $timestamps = false;

    protected $fillable = ['seller_profile_id', 'offer_id', 'storage_group_id', 'triggered_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }
}
