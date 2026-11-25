<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CategoryTypeCode $code
 * @property array<string, string> $name
 * @property string|null $icon
 * @property int $sort
 * @property bool $is_enabled
 * @property array<string, mixed>|null $default_policy
 */
final class CategoryType extends Model
{
    use Auditable;
    use HasTranslations;

    protected $fillable = ['code', 'name', 'icon', 'sort', 'is_enabled', 'default_policy'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => CategoryTypeCode::class,
            'name' => 'array',
            'sort' => 'integer',
            'is_enabled' => 'boolean',
            'default_policy' => 'array',
        ];
    }

    /**
     * @return HasMany<GameCategory, $this>
     */
    public function gameCategories(): HasMany
    {
        return $this->hasMany(GameCategory::class);
    }

    /**
     * @return HasMany<ServiceType, $this>
     */
    public function serviceTypes(): HasMany
    {
        return $this->hasMany(ServiceType::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    /** URL segment, e.g. "top-up", "video-games". */
    public function urlSlug(): string
    {
        return str_replace('_', '-', $this->code->value);
    }

    public static function fromUrlSlug(string $slug): ?CategoryTypeCode
    {
        return CategoryTypeCode::tryFrom(str_replace('-', '_', $slug));
    }
}
