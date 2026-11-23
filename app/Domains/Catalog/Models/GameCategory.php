<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Schema\AttributeSchema;
use App\Domains\Catalog\Schema\BuyerInputSchema;
use App\Shared\Audit\Auditable;
use App\Shared\Support\Translations;
use Database\Factories\GameCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A game × category type page, e.g. "FC 27 Coins", "Fortnite Accounts" (spec 5.2, glossary).
 *
 * @property int $id
 * @property int $game_id
 * @property int $category_type_id
 * @property string $slug
 * @property array<string, string>|null $label_override
 * @property array<int, mixed>|null $attribute_schema
 * @property array<int, mixed>|null $buyer_input_schema
 * @property list<string>|null $allowed_delivery_methods
 * @property array<string, mixed>|null $policy_override
 * @property array<string, string>|null $notice
 * @property int $offer_count_cached
 * @property int $product_count_cached
 * @property bool $is_enabled
 * @property-read Game $game
 * @property-read CategoryType $type
 */
final class GameCategory extends Model
{
    use Auditable;

    /** @use HasFactory<GameCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'game_id', 'category_type_id', 'slug', 'label_override', 'attribute_schema', 'buyer_input_schema',
        'allowed_delivery_methods', 'policy_override', 'notice', 'is_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'label_override' => 'array',
            'attribute_schema' => 'array',
            'buyer_input_schema' => 'array',
            'allowed_delivery_methods' => 'array',
            'policy_override' => 'array',
            'notice' => 'array',
            'offer_count_cached' => 'integer',
            'product_count_cached' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A malformed schema must never be saved: the listing page builds its filters from it.
        self::saving(static function (self $gameCategory): void {
            AttributeSchema::fromArray($gameCategory->attribute_schema);
            BuyerInputSchema::fromArray($gameCategory->buyer_input_schema);
        });
    }

    protected static function newFactory(): GameCategoryFactory
    {
        return GameCategoryFactory::new();
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<CategoryType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(CategoryType::class, 'category_type_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Visible to the public: the row, its game and its category type are all enabled.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('game_categories.is_enabled', true)
            ->whereHas('game', fn (Builder $q) => $q->where('is_enabled', true))
            ->whereHas('type', fn (Builder $q) => $q->where('is_enabled', true));
    }

    public function attributeSchema(): AttributeSchema
    {
        return AttributeSchema::fromArray($this->attribute_schema);
    }

    public function buyerInputSchema(): BuyerInputSchema
    {
        return BuyerInputSchema::fromArray($this->buyer_input_schema);
    }

    /** Game-specific label ("Coins", "V-Bucks") or the category type name. */
    public function label(?string $locale = null): string
    {
        $override = Translations::pick($this->label_override, $locale);

        return $override !== '' ? $override : $this->type->translate('name', $locale);
    }

    /** Page title, e.g. "Fortnite Accounts". */
    public function title(?string $locale = null): string
    {
        return $this->game->translate('name', $locale).' '.$this->label($locale);
    }

    public function noticeText(?string $locale = null): ?string
    {
        $text = Translations::pick($this->notice, $locale);

        return $text !== '' ? $text : null;
    }

    public function path(string $locale): string
    {
        return "/{$locale}/{$this->game->slug}/{$this->slug}";
    }
}
