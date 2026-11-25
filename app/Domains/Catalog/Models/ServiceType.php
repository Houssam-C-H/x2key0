<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Offers\Models\Offer;
use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Database\Factories\ServiceTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of service inside a category type, e.g. "Rank boost" under Boosting (ADR-032). Staff switch it on and off;
 * while off, sellers cannot list under it and its offers leave the storefront.
 *
 * @property int $id
 * @property int $category_type_id
 * @property string $code
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property int $sort
 * @property bool $is_enabled
 * @property-read CategoryType $categoryType
 */
final class ServiceType extends Model
{
    use Auditable;

    /** @use HasFactory<ServiceTypeFactory> */
    use HasFactory;

    use HasTranslations;

    protected $fillable = ['category_type_id', 'code', 'name', 'description', 'sort', 'is_enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'sort' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    protected static function newFactory(): ServiceTypeFactory
    {
        return ServiceTypeFactory::new();
    }

    /**
     * @return BelongsTo<CategoryType, $this>
     */
    public function categoryType(): BelongsTo
    {
        return $this->belongsTo(CategoryType::class);
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('service_types.is_enabled', true);
    }

    /**
     * Enabled service types a seller can list under for this category type, in staff order.
     *
     * @return Collection<int, self>
     */
    public static function availableFor(int $categoryTypeId): Collection
    {
        return self::query()->enabled()->where('category_type_id', $categoryTypeId)->orderBy('sort')->orderBy('id')->get();
    }
}
