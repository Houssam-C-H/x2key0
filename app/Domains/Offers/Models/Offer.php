<?php

declare(strict_types=1);

namespace App\Domains\Offers\Models;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Offers\Enums\Fulfillment;
use App\Domains\Offers\Enums\InsuranceMode;
use App\Domains\Offers\Enums\OfferStatus;
use App\Domains\Offers\Enums\PublishMode;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Storage\Models\StorageGroup;
use App\Shared\Audit\Auditable;
use App\Shared\Money\MoneyCast;
use App\Shared\Support\Translations;
use Brick\Money\Money;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A seller's priced listing (spec 5.4). `status` changes only through OfferStateMachine and the price only
 * through SaveOffer / BulkUpdateOffers (version++, price history), so neither is mass assignable.
 *
 * @property int $id
 * @property string $public_id
 * @property int $seller_profile_id
 * @property int $game_category_id
 * @property int|null $product_id
 * @property string $title
 * @property array<string, string>|null $description
 * @property DeliveryMethod $delivery_method
 * @property Fulfillment $fulfillment
 * @property int|null $storage_group_id
 * @property array<string, mixed>|null $attribute_values
 * @property string $attributes_hash
 * @property int $unit_price_amount
 * @property string $currency
 * @property Money $unit_price
 * @property int $min_qty
 * @property int|null $max_qty
 * @property string|null $unit_label
 * @property bool $integer_multiple
 * @property list<array{min_qty: int, unit_price_amount: int}>|null $quantity_tiers
 * @property int $delivery_time_minutes
 * @property int|null $manual_stock_qty
 * @property int $sold_count
 * @property OfferStatus $status
 * @property int|null $account_recovery_protection_days
 * @property int $insurance_days
 * @property InsuranceMode $insurance_mode
 * @property int $sort_weight
 * @property int|null $acquisition_cost_amount
 * @property PublishMode $publish_mode
 * @property int $listing_days
 * @property Carbon|null $expires_at
 * @property Carbon|null $published_at
 * @property string|null $status_reason
 * @property int|null $duplicate_of_offer_id
 * @property int $version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read SellerProfile $seller
 * @property-read GameCategory $gameCategory
 * @property-read Product|null $product
 * @property-read StorageGroup|null $storageGroup
 * @property-read Offer|null $duplicateOf
 */
final class Offer extends Model
{
    use Auditable;

    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'seller_profile_id', 'game_category_id', 'product_id', 'title', 'description', 'delivery_method', 'fulfillment',
        'storage_group_id', 'attribute_values', 'attributes_hash', 'currency', 'min_qty', 'max_qty', 'unit_label',
        'integer_multiple', 'quantity_tiers', 'delivery_time_minutes', 'manual_stock_qty', 'account_recovery_protection_days',
        'insurance_days', 'insurance_mode', 'sort_weight', 'acquisition_cost_amount', 'publish_mode', 'listing_days',
        'duplicate_of_offer_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'description' => 'array',
            'delivery_method' => DeliveryMethod::class,
            'fulfillment' => Fulfillment::class,
            'attribute_values' => 'array',
            'unit_price_amount' => 'integer',
            'unit_price' => MoneyCast::class.':unit_price_amount,currency',
            'min_qty' => 'integer',
            'max_qty' => 'integer',
            'integer_multiple' => 'boolean',
            'quantity_tiers' => 'array',
            'delivery_time_minutes' => 'integer',
            'manual_stock_qty' => 'integer',
            'sold_count' => 'integer',
            'status' => OfferStatus::class,
            'account_recovery_protection_days' => 'integer',
            'insurance_days' => 'integer',
            'insurance_mode' => InsuranceMode::class,
            'sort_weight' => 'integer',
            'acquisition_cost_amount' => 'integer',
            'publish_mode' => PublishMode::class,
            'listing_days' => 'integer',
            'expires_at' => 'datetime',
            'published_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function newFactory(): OfferFactory
    {
        return OfferFactory::new();
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    /**
     * @return BelongsTo<GameCategory, $this>
     */
    public function gameCategory(): BelongsTo
    {
        return $this->belongsTo(GameCategory::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<StorageGroup, $this>
     */
    public function storageGroup(): BelongsTo
    {
        return $this->belongsTo(StorageGroup::class);
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_offer_id');
    }

    /**
     * @return HasMany<OfferStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(OfferStatusChange::class);
    }

    /**
     * @return HasMany<OfferPriceHistory, $this>
     */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(OfferPriceHistory::class);
    }

    /**
     * Publicly listed: active offer of a public store in a visible game×category.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('offers.status', OfferStatus::Active)
            ->whereHas('seller', fn (Builder $q) => $q->public())
            ->whereHas('gameCategory', fn (Builder $q) => $q->visible());
    }

    /**
     * Ranking used by the product page and buy box (spec 1.5 "Recommended"): insured offers first
     * (1.8 #10), then price, then the seller's level boost, then the oldest listing.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRecommended(Builder $query): void
    {
        $query->leftJoin('seller_profiles as rank_sp', 'rank_sp.id', '=', 'offers.seller_profile_id')
            ->leftJoin('seller_levels as rank_sl', 'rank_sl.id', '=', 'rank_sp.seller_level_id')
            ->select('offers.*')
            ->orderByRaw('CASE WHEN offers.insurance_days > 0 THEN 0 ELSE 1 END')
            ->orderBy('offers.unit_price_amount')
            ->orderByRaw('COALESCE(rank_sl.rank_boost, 0) DESC')
            ->orderBy('offers.id');
    }

    /** Units the buyer can buy now: storage count for auto delivery, the typed number otherwise. */
    public function stock(): int
    {
        if ($this->fulfillment === Fulfillment::Auto) {
            return $this->storageGroup->available_count_cached ?? 0;
        }

        return $this->manual_stock_qty ?? 0;
    }

    public function descriptionText(?string $locale = null): string
    {
        return Translations::pick($this->description, $locale);
    }

    public function path(string $locale): string
    {
        return "/{$locale}/offer/{$this->public_id}";
    }
}
