<?php

declare(strict_types=1);

namespace App\Domains\Storage\Models;

use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Enums\StorageItemType;
use App\Shared\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One kind of stock in a seller's vault (spec 5.5), linked to auto-delivered offers.
 * `available_count_cached` = available, unflagged items; refreshed by RefreshStorageStock.
 *
 * @property int $id
 * @property int $seller_profile_id
 * @property string $name
 * @property StorageItemType $item_type
 * @property int $low_stock_threshold
 * @property int $available_count_cached
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read SellerProfile $seller
 */
final class StorageGroup extends Model
{
    use Auditable;

    protected $fillable = ['seller_profile_id', 'name', 'item_type', 'low_stock_threshold'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_type' => StorageItemType::class,
            'low_stock_threshold' => 'integer',
            'available_count_cached' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    /**
     * @return HasMany<StorageItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StorageItem::class);
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * @return HasMany<StorageImportBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(StorageImportBatch::class);
    }

    public function isLow(): bool
    {
        return $this->available_count_cached === 0 || $this->available_count_cached < $this->low_stock_threshold;
    }
}
