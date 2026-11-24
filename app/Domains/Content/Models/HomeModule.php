<?php

declare(strict_types=1);

namespace App\Domains\Content\Models;

use App\Shared\Audit\Auditable;
use App\Shared\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One home page section (spec 1.3), ordered by `sort`, switched by `is_enabled`; `title` overrides the default
 * heading, `config.limit` the number of tiles. Staff pin items through `home_module_items`.
 *
 * @property int $id
 * @property string $code
 * @property array<string, string>|null $title
 * @property array<string, mixed>|null $config
 * @property int $sort
 * @property bool $is_enabled
 */
final class HomeModule extends Model
{
    use Auditable;
    use HasTranslations;

    /** The 12 observed modules, top to bottom (spec 1.3). */
    public const array CODES = [
        'hero', 'browsing_history', 'hot_game', 'trending_topup', 'trending_giftcards', 'trending_videogames',
        'community_ratings', 'affiliate_banner', 'new_game', 'game_news', 'payment_strip', 'footer',
    ];

    /** What can be pinned in which module. */
    public const array ITEM_TYPES = [
        'hot_game' => 'game',
        'trending_topup' => 'game_category',
        'trending_giftcards' => 'game_category',
        'trending_videogames' => 'game_category',
        'new_game' => 'game_category',
        'community_ratings' => 'comment',
        'game_news' => 'article',
    ];

    protected $fillable = ['code', 'title', 'config', 'sort', 'is_enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['title' => 'array', 'config' => 'array', 'is_enabled' => 'boolean', 'sort' => 'integer'];
    }

    public function limit(int $default): int
    {
        $limit = $this->config['limit'] ?? null;

        return is_int($limit) && $limit > 0 && $limit <= 40 ? $limit : $default;
    }

    /** @return HasMany<HomeModuleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(HomeModuleItem::class);
    }

    /**
     * Pinned item ids in order.
     *
     * @return list<int>
     */
    public function pinnedIds(): array
    {
        return array_values($this->items->where('pinned', true)->sortBy('sort')->pluck('item_id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all());
    }
}
