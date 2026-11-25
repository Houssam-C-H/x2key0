<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read models for public catalog navigation pages. Returns plain arrays ready for Inertia props.
 */
final class CatalogReader
{
    private const int MEGA_MENU_GAMES = 10;

    /**
     * Mega menu (spec 1.1): one column per enabled category type with its top games.
     *
     * @return list<array<string, mixed>>
     */
    public function megaMenu(string $locale): array
    {
        return CatalogCache::remember("megamenu.{$locale}", function () use ($locale): array {
            $types = CategoryType::query()->enabled()->orderBy('sort')->get();
            $columns = [];

            foreach ($types as $type) {
                $categories = GameCategory::query()
                    ->visible()
                    ->where('category_type_id', $type->id)
                    ->join('games', 'games.id', '=', 'game_categories.game_id')
                    ->orderByDesc('games.is_hot')
                    ->orderByDesc('game_categories.offer_count_cached')
                    ->orderByDesc('game_categories.product_count_cached')
                    ->orderBy('games.slug')
                    ->select('game_categories.*')
                    ->with(['game', 'type'])
                    ->limit(self::MEGA_MENU_GAMES)
                    ->get();

                $columns[] = [
                    'code' => $type->code->value,
                    'label' => $type->translate('name', $locale),
                    'href' => "/{$locale}/catalog/{$type->urlSlug()}",
                    'games' => array_values($categories->map(fn (GameCategory $gc): array => [
                        'name' => $gc->game->translate('name', $locale),
                        'href' => $gc->path($locale),
                        'count' => self::count($gc),
                        'icon' => $gc->game->iconUrl(),
                    ])->all()),
                ];
            }

            return $columns;
        });
    }

    /**
     * Game Index (spec 1.4.4): every enabled game with its visible categories, grouped A–Z / #, plus HOT.
     *
     * @return array{letters: list<string>, groups: array<string, list<array<string, mixed>>>, hot: list<array<string, mixed>>}
     */
    public function gameIndex(string $locale): array
    {
        return CatalogCache::remember("gameindex.{$locale}", function () use ($locale): array {
            $games = $this->gamesWithCategories()->get();

            $groups = [];
            $hot = [];

            foreach ($games as $game) {
                if ($game->categories->isEmpty()) {
                    continue;
                }

                $card = $this->gameCard($game, $locale);
                $groups[$game->indexLetter()][] = $card;

                if ($game->is_hot) {
                    $hot[] = $card;
                }
            }

            ksort($groups);
            // "#" sorts before letters in ksort; keep it last like most A–Z indexes.
            if (isset($groups['#'])) {
                $digits = $groups['#'];
                unset($groups['#']);
                $groups['#'] = $digits;
            }

            foreach ($groups as &$list) {
                usort($list, static fn (array $a, array $b): int => strcasecmp(is_string($a['name']) ? $a['name'] : '', is_string($b['name']) ? $b['name'] : ''));
            }
            unset($list);

            return ['letters' => array_keys($groups), 'groups' => $groups, 'hot' => $hot];
        });
    }

    /**
     * Category hub (spec 1.4.2): all games of one category type.
     *
     * @return list<array<string, mixed>>
     */
    public function categoryHub(CategoryType $type, string $locale): array
    {
        return array_values(GameCategory::query()
            ->visible()
            ->where('category_type_id', $type->id)
            ->with(['game', 'type'])
            ->get()
            ->map(fn (GameCategory $gc): array => [
                'name' => $gc->game->translate('name', $locale),
                'label' => $gc->label($locale),
                'href' => $gc->path($locale),
                'count' => self::count($gc),
                'letter' => $gc->game->indexLetter(),
                'hot' => $gc->game->is_hot,
                'icon' => $gc->game->iconUrl(),
            ])
            ->sortBy(fn (array $row): string => mb_strtolower($row['name']))
            ->all());
    }

    /**
     * Category tabs of one game (spec 1.4.1: "Accounts 4049 · Game DLC 1299 · …").
     *
     * @return list<array<string, mixed>>
     */
    public function categoryTabs(Game $game, string $locale, ?int $activeId = null): array
    {
        return array_values($game->categories()
            ->visible()
            ->with(['type', 'game'])
            ->get()
            ->sortBy([
                fn (GameCategory $a, GameCategory $b): int => self::count($b) <=> self::count($a),
                fn (GameCategory $a, GameCategory $b): int => $a->type->sort <=> $b->type->sort,
            ])
            ->map(fn (GameCategory $gc): array => [
                'code' => $gc->type->code->value,
                'label' => $gc->label($locale),
                'href' => $gc->path($locale),
                'count' => self::count($gc),
                'active' => $gc->id === $activeId,
            ])
            ->all());
    }

    /**
     * Header search (spec 1.1): games by name/alias and products by name, optionally within one category type.
     *
     * @return array{games: list<array<string, mixed>>, products: list<array<string, mixed>>}
     */
    public function search(string $text, ?CategoryTypeCode $type, string $locale): array
    {
        $needle = mb_strtolower(trim($text));

        if (mb_strlen($needle) < 2) {
            return ['games' => [], 'products' => []];
        }

        $games = $this->gamesWithCategories($type)->get()
            ->filter(function (Game $game) use ($needle): bool {
                $haystacks = [...array_values($game->name), ...($game->search_aliases ?? []), $game->slug];
                foreach ($haystacks as $value) {
                    if (str_contains(mb_strtolower($value), $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->filter(fn (Game $game): bool => $game->categories->isNotEmpty())
            ->sortByDesc(fn (Game $game): int => $game->is_hot ? 1 : 0)
            ->take(24)
            ->map(fn (Game $game): array => $this->gameCard($game, $locale))
            ->values()
            ->all();
        $games = array_values($games);

        $like = '%'.addcslashes($needle, '%_\\').'%';

        $products = Product::query()
            ->active()
            ->whereHas('gameCategory', function (Builder $q) use ($type): void {
                $q->visible();
                if ($type !== null) {
                    $q->whereHas('type', fn (Builder $t) => $t->where('code', $type->value));
                }
            })
            ->whereRaw('LOWER(CAST(name AS TEXT)) LIKE ?', [$like])
            ->with(['gameCategory.game', 'gameCategory.type', 'platform', 'region'])
            ->limit(48)
            ->get()
            ->map(fn (Product $p): array => CatalogPresenter::productCard($p, $locale))
            ->all();

        return ['games' => $games, 'products' => array_values($products)];
    }

    /**
     * @return array<string, mixed>
     */
    public function gameCard(Game $game, string $locale): array
    {
        $categories = array_values($game->categories
            ->sortBy(fn (GameCategory $gc): int => $gc->type->sort)
            ->map(fn (GameCategory $gc): array => [
                'code' => $gc->type->code->value,
                'label' => $gc->label($locale),
                'href' => $gc->path($locale),
                'count' => self::count($gc),
            ])
            ->all());

        return [
            'slug' => $game->slug,
            'name' => $game->translate('name', $locale),
            'href' => "/{$locale}/{$game->slug}",
            'hot' => $game->is_hot,
            'isNew' => $game->is_new,
            'icon' => $game->iconUrl(),
            'count' => array_sum(array_column($categories, 'count')),
            'categories' => $categories,
        ];
    }

    /**
     * Shown count: offers once they exist (M5), products until then.
     */
    public static function count(GameCategory $gameCategory): int
    {
        return $gameCategory->offer_count_cached > 0 ? $gameCategory->offer_count_cached : $gameCategory->product_count_cached;
    }

    /**
     * @return Builder<Game>
     */
    private function gamesWithCategories(?CategoryTypeCode $type = null): Builder
    {
        return Game::query()
            ->enabled()
            ->orderBy('slug')
            ->with(['categories' => function ($q) use ($type): void {
                /** @var HasMany<GameCategory, Game> $q */
                $q->visible()->with(['type', 'game']);
                if ($type !== null) {
                    $q->whereHas('type', fn (Builder $t) => $t->where('code', $type->value));
                }
            }]);
    }
}
