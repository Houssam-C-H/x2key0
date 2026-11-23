<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\ProductStatus;
use App\Shared\Audit\Auditable;
use App\Shared\Money\MoneyCast;
use App\Shared\Support\HasTranslations;
use Brick\Money\Money;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Catalog product sold by many sellers (spec 5.2). Offers attach to it from M5.
 *
 * @property int $id
 * @property string $public_id
 * @property int $game_category_id
 * @property int|null $platform_id
 * @property int|null $region_id
 * @property string $slug
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property string|null $image_path
 * @property array<string, mixed>|null $attribute_values
 * @property Money|null $msrp
 * @property int|null $msrp_amount
 * @property string|null $msrp_currency
 * @property int|null $lowest_price_amount_cached
 * @property int $offer_count_cached
 * @property ProductStatus $status
 * @property Carbon $created_at
 * @property-read GameCategory $gameCategory
 * @property-read Platform|null $platform
 * @property-read Region|null $region
 */
final class Product extends Model
{
    use Auditable;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasTranslations;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'game_category_id', 'platform_id', 'region_id', 'slug', 'name', 'description', 'image_path',
        'attribute_values', 'msrp', 'msrp_amount', 'msrp_currency', 'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'attribute_values' => 'array',
            'msrp' => MoneyCast::class.':msrp_amount,msrp_currency',
            'lowest_price_amount_cached' => 'integer',
            'offer_count_cached' => 'integer',
            'status' => ProductStatus::class,
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function booted(): void
    {
        // Attributes are validated and normalised against the game×category schema on every save.
        self::saving(static function (self $product): void {
            $product->attribute_values = $product->gameCategory->attributeSchema()->normalise($product->attribute_values ?? []);
        });
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * @return BelongsTo<GameCategory, $this>
     */
    public function gameCategory(): BelongsTo
    {
        return $this->belongsTo(GameCategory::class);
    }

    /**
     * @return BelongsTo<Platform, $this>
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('products.status', ProductStatus::Active);
    }

    public function path(string $locale): string
    {
        return $this->gameCategory->path($locale).'/'.$this->slug;
    }
}
