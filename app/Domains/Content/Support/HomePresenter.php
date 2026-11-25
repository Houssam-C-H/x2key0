<?php

declare(strict_types=1);

namespace App\Domains\Content\Support;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Support\CatalogReader;
use App\Domains\Content\Models\HomeModule;
use App\Domains\Content\Models\NewsArticle;
use App\Domains\Offers\Models\Offer;
use App\Domains\Payments\Models\PaymentMethod;
use App\Domains\Reviews\Support\ReviewPresenter;
use App\Domains\Sellers\Models\SellerProfile;
use App\Shared\Support\Settings;
use Illuminate\Database\Eloquent\Collection;

/**
 * The home page (spec 1.3) from `home_modules`: enabled modules in `sort` order, each with its data. Shared modules
 * are cached per locale (HomeCache); Browsing History is per viewer and added by the controller. Pinned items
 * (home_module_items) come first, then the automatic picks (trend score, newest, featured).
 */
final class HomePresenter
{
    /** Hot Game columns (spec 1.3 #3). */
    public const array HOT_TYPES = [CategoryTypeCode::Currency, CategoryTypeCode::Items, CategoryTypeCode::Accounts, CategoryTypeCode::GiftCards];

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return list<array{code: string, title: string|null, data: array<string, mixed>}>
     */
    public function modules(string $locale): array
    {
        /** @var list<array{code: string, title: string|null, data: array<string, mixed>}> */
        return HomeCache::remember("modules.{$locale}", function () use ($locale): array {
            $out = [];
            foreach (self::enabledModules() as $module) {
                $data = match ($module->code) {
                    'hero', 'browsing_history' => [],
                    'hot_game' => $this->hotGame($module, $locale),
                    'trending_topup' => $this->trending($module, CategoryTypeCode::TopUp, 9, $locale),
                    'trending_giftcards' => $this->trending($module, CategoryTypeCode::GiftCards, 10, $locale),
                    'trending_videogames' => $this->trending($module, CategoryTypeCode::VideoGames, 10, $locale),
                    'community_ratings' => ['items' => ReviewPresenter::community($module->limit(4), $locale, $module->pinnedIds())],
                    'affiliate_banner' => ['rate' => $this->settings->int('affiliate_commission_bps'), 'href' => "/{$locale}/affiliate"],
                    'new_game' => $this->newGames($module, $locale),
                    'game_news' => $this->news($module, $locale),
                    'payment_strip' => $this->payments($locale),
                    default => null,
                };
                if ($data === null) {
                    continue; // e.g. `footer`: always rendered by the layout
                }
                $title = $module->title === null ? '' : $module->translate('title', $locale);
                $out[] = ['code' => $module->code, 'title' => $title === '' ? null : $title, 'data' => $data];
            }

            return $out;
        });
    }

    /**
     * Enabled modules in order; before HomeModuleSeeder has run, the 12 defaults (spec 1.3) so the page is never empty.
     *
     * @return \Illuminate\Support\Collection<int, HomeModule>
     */
    private static function enabledModules(): \Illuminate\Support\Collection
    {
        if (HomeModule::query()->exists()) {
            return HomeModule::query()->where('is_enabled', true)->with('items')->orderBy('sort')->get()->toBase();
        }

        return collect(HomeModule::CODES)->map(static function (string $code, int $i): HomeModule {
            $module = new HomeModule(['code' => $code, 'sort' => ($i + 1) * 10, 'is_enabled' => true]);
            $module->setRelation('items', new Collection);

            return $module;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function hotGame(HomeModule $module, string $locale): array
    {
        $pinned = $module->pinnedIds();
        $columns = [];

        foreach (self::HOT_TYPES as $code) {
            $type = CategoryType::query()->where('code', $code->value)->first();
            if ($type === null) {
                continue;
            }
            $rows = GameCategory::query()->visible()->where('category_type_id', $type->id)->with(['game', 'type'])
                ->join('games', 'games.id', '=', 'game_categories.game_id')
                ->select('game_categories.*')
                ->orderByDesc('games.hot_score')->orderByDesc('game_categories.offer_count_cached')
                ->limit(30)->get();
            $rows = self::pinFirst($rows, $pinned, static fn (GameCategory $gc): int => $gc->game_id)->take($module->limit(4));

            $columns[] = [
                'type' => $code->value,
                'label' => __($code->labelKey()),
                'href' => "/{$locale}/catalog/".str_replace('_', '-', $code->value),
                'games' => array_values($rows->filter(fn (GameCategory $gc): bool => CatalogReader::count($gc) > 0)->map(fn (GameCategory $gc): array => [
                    'name' => $gc->game->translate('name', $locale),
                    'href' => $gc->path($locale),
                    'count' => CatalogReader::count($gc),
                    'icon' => $gc->game->iconUrl(),
                ])->values()->all()),
            ];
        }

        return ['columns' => $columns];
    }

    /**
     * @return array<string, mixed>
     */
    private function trending(HomeModule $module, CategoryTypeCode $code, int $default, string $locale): array
    {
        $type = CategoryType::query()->where('code', $code->value)->first();
        if ($type === null) {
            return ['tiles' => [], 'more' => null];
        }

        $rows = GameCategory::query()->visible()->where('category_type_id', $type->id)->with(['game', 'type'])
            ->orderByDesc('trend_score')->orderByDesc('offer_count_cached')->limit(40)->get();
        $rows = self::pinFirst($rows, $module->pinnedIds(), static fn (GameCategory $gc): int => $gc->id, $type->id)->take($module->limit($default));

        $prices = self::lowestPrices(array_values($rows->pluck('id')->all()));

        return [
            'tiles' => array_values($rows->map(fn (GameCategory $gc): array => self::tile($gc, $locale, $prices[$gc->id] ?? null))->all()),
            'more' => "/{$locale}/catalog/".str_replace('_', '-', $code->value),
        ];
    }

    /**
     * New Game (spec 1.3 #9): newest game×category pages, 4 columns of 10.
     *
     * @return array<string, mixed>
     */
    private function newGames(HomeModule $module, string $locale): array
    {
        $rows = GameCategory::query()->visible()->with(['game', 'type'])->orderByDesc('created_at')->orderByDesc('id')->limit(60)->get();
        $rows = self::pinFirst($rows, $module->pinnedIds(), static fn (GameCategory $gc): int => $gc->id)->take($module->limit(40));
        $links = array_values($rows->map(static fn (GameCategory $gc): array => ['game' => $gc->game->translate('name', $locale), 'label' => $gc->label($locale), 'href' => $gc->path($locale), 'icon' => $gc->game->iconUrl()])->all());

        return ['columns' => array_chunk($links, max(1, (int) ceil(count($links) / 4)))];
    }

    /**
     * @return array<string, mixed>
     */
    private function news(HomeModule $module, string $locale): array
    {
        $pinned = $module->pinnedIds();
        $articles = NewsArticle::query()->published()->orderByDesc('published_at')->limit(20)->get();
        if ($pinned !== []) {
            $articles = NewsArticle::query()->published()->whereIn('id', $pinned)->get()
                ->sortBy(static fn (NewsArticle $a): int|false => array_search($a->id, $pinned, true))
                ->concat($articles)->unique('id')->values();
        }

        return [
            'articles' => array_values($articles->take($module->limit(4))->map(static fn (NewsArticle $a): array => self::article($a, $locale))->all()),
            'more' => "/{$locale}/news",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payments(string $locale): array
    {
        return ['methods' => array_values(PaymentMethod::query()->where('is_enabled', true)->orderBy('sort')->get()
            ->map(static fn (PaymentMethod $m): array => ['code' => $m->code, 'name' => $m->translate('name', $locale), 'logo' => self::asset($m->logo_path)])->all())];
    }

    /**
     * @return array<string, mixed>
     */
    public static function article(NewsArticle $a, string $locale): array
    {
        return [
            'slug' => $a->slug,
            'title' => $a->translate('title', $locale),
            'excerpt' => $a->excerpt === null ? null : $a->translate('excerpt', $locale),
            'cover' => self::asset($a->cover_path),
            'href' => $a->path($locale),
            'publishedAt' => $a->published_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tile(GameCategory $gc, string $locale, ?int $from): array
    {
        return [
            'name' => $gc->game->translate('name', $locale),
            'label' => $gc->label($locale),
            'href' => $gc->path($locale),
            'count' => CatalogReader::count($gc),
            'image' => $gc->game->coverUrl(),
            'from' => $from,
        ];
    }

    /**
     * Lowest live offer price per game×category ("from $X" on tiles).
     *
     * @param  list<mixed>  $ids
     * @return array<int, int>
     */
    private static function lowestPrices(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Offer::query()->live()->whereIn('offers.game_category_id', $ids)
            ->groupBy('offers.game_category_id')->selectRaw('offers.game_category_id as gc, MIN(offers.unit_price_amount) as low')->toBase()->get() as $row) {
            if (is_numeric($row->gc ?? null) && is_numeric($row->low ?? null)) {
                $out[(int) $row->gc] = (int) $row->low;
            }
        }

        return $out;
    }

    /**
     * Hero figures and popular searches (social proof above the fold), cached with the modules.
     *
     * @return array{offers: int, sellers: int, games: int, popular: list<array{name: string, href: string}>}
     */
    public function hero(string $locale): array
    {
        /** @var array{offers: int, sellers: int, games: int, popular: list<array{name: string, href: string}>, types: array<string, int>} */
        return HomeCache::remember("hero.{$locale}", static function () use ($locale): array {
            $popular = Game::query()->where('is_enabled', true)->orderByDesc('hot_score')->orderByDesc('offer_count_cached')->limit(6)->get();

            // Live offers per category type code (category cards under the hero).
            $types = [];
            $rows = Offer::query()->live()
                ->join('game_categories as hero_gc', 'hero_gc.id', '=', 'offers.game_category_id')
                ->join('category_types as hero_ct', 'hero_ct.id', '=', 'hero_gc.category_type_id')
                ->groupBy('hero_ct.code')
                ->selectRaw('hero_ct.code as code, COUNT(*) as aggregate')
                ->toBase()->get();
            foreach ($rows as $row) {
                $code = $row->code ?? null;
                $count = $row->aggregate ?? null;
                if (is_string($code) && is_numeric($count)) {
                    $types[$code] = (int) $count;
                }
            }

            return [
                'offers' => Offer::query()->live()->count(),
                'sellers' => SellerProfile::query()->public()->count(),
                'games' => Game::query()->where('is_enabled', true)->count(),
                'popular' => array_values($popular->map(static fn (Game $g): array => ['name' => $g->translate('name', $locale), 'href' => "/{$locale}/{$g->slug}", 'icon' => $g->iconUrl()])->all()),
                'types' => $types,
            ];
        });
    }

    /**
     * Pinned items first (in pin order), then the rest; for game×category modules a pinned row of another category
     * type is ignored.
     *
     * @param  Collection<int, GameCategory>  $rows
     * @param  list<int>  $pinned
     * @param  callable(GameCategory): int  $key
     * @return \Illuminate\Support\Collection<int, GameCategory>
     */
    private static function pinFirst(Collection $rows, array $pinned, callable $key, ?int $typeId = null): \Illuminate\Support\Collection
    {
        if ($pinned === []) {
            return $rows->toBase();
        }

        $extra = GameCategory::query()->visible()->with(['game', 'type'])->when($typeId !== null, fn ($q) => $q->where('category_type_id', $typeId))
            ->where(fn ($q) => $q->whereIn('game_categories.id', $pinned)->orWhereIn('game_categories.game_id', $pinned))->get();
        $all = $rows->concat($extra)->unique('id');

        return $all->sortBy(static function (GameCategory $gc) use ($pinned, $key): int {
            $pos = array_search($key($gc), $pinned, true);

            return $pos === false ? PHP_INT_MAX : $pos;
        })->values()->toBase();
    }

    /** Stored path → public URL (absolute URLs and site paths are kept). */
    public static function asset(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Game::publicUrl($path);
    }

    /**
     * Game list helper for admin pickers.
     *
     * @return array<int, string>
     */
    public static function gameOptions(): array
    {
        return Game::query()->orderBy('slug')->limit(500)->get()->mapWithKeys(static fn (Game $g): array => [$g->id => $g->translate('name', 'en')])->all();
    }
}
