<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domains\Catalog\Enums\AttributeType;
use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Platform;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\Region;
use App\Domains\Catalog\Search\CatalogSearch;
use App\Domains\Catalog\Search\ListingQuery;
use App\Domains\Catalog\Support\CatalogPresenter;
use App\Domains\Catalog\Support\CatalogReader;
use App\Http\Support\Seo;
use App\Shared\Support\Translations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public catalog pages (spec 1.4 / 1.5, routes Part 20). Disabled games, category types or
 * game×categories return 404, as do unknown slugs.
 */
final class CatalogController
{
    public function __construct(private readonly CatalogReader $reader) {}

    public function games(Request $request, string $locale): Response
    {
        return Inertia::render('Public/Catalog/GameIndex', [
            'index' => $this->reader->gameIndex($locale),
            'seo' => Seo::make(__('catalog.game_index.title'), __('catalog.game_index.description'), '/games', $locale),
        ]);
    }

    public function categoryHub(Request $request, string $locale, string $type): Response
    {
        $code = CategoryType::fromUrlSlug($type);
        $categoryType = $code === null ? null : CategoryType::query()->enabled()->where('code', $code)->first();
        abort_if($categoryType === null, 404);

        $name = $categoryType->translate('name', $locale);

        return Inertia::render('Public/Catalog/CategoryHub', [
            'type' => ['code' => $categoryType->code->value, 'name' => $name],
            'types' => CategoryType::query()->enabled()->orderBy('sort')->get()->map(fn (CategoryType $t): array => [
                'code' => $t->code->value,
                'name' => $t->translate('name', $locale),
                'href' => "/{$locale}/catalog/{$t->urlSlug()}",
                'active' => $t->id === $categoryType->id,
            ])->all(),
            'games' => $this->reader->categoryHub($categoryType, $locale),
            'seo' => Seo::make($name, __('catalog.category_hub.description', ['type' => $name]), "/catalog/{$type}", $locale),
        ]);
    }

    public function search(Request $request, string $locale): Response
    {
        $text = is_string($q = $request->query('q')) ? mb_substr(trim($q), 0, 100) : '';
        $type = is_string($t = $request->query('type')) ? CategoryTypeCode::tryFrom($t) : null;

        return Inertia::render('Public/Catalog/Search', [
            'q' => $text,
            'type' => $type?->value,
            'results' => $this->reader->search($text, $type, $locale),
            'seo' => Seo::make(__('catalog.search.title'), __('catalog.search.title'), '/search', $locale, noindex: true),
        ]);
    }

    public function game(Request $request, string $locale, string $game): Response
    {
        $model = $this->findGame($game);
        $tabs = $this->reader->categoryTabs($model, $locale);
        abort_if($tabs === [], 404);

        $name = $model->translate('name', $locale);
        $crumbs = [['name' => __('dashboard.home'), 'href' => "/{$locale}"], ['name' => $name, 'href' => "/{$locale}/{$model->slug}"]];

        return Inertia::render('Public/Catalog/GameHub', [
            'game' => ['name' => $name, 'slug' => $model->slug, 'publisher' => $model->publisher],
            'tabs' => $tabs,
            'breadcrumbs' => $crumbs,
            'seo' => Seo::make($name, __('catalog.game_hub.description', ['game' => $name]), "/{$model->slug}", $locale, jsonLd: [Seo::breadcrumbList($crumbs)]),
        ]);
    }

    public function listing(Request $request, CatalogSearch $search, string $locale, string $game, string $category): Response
    {
        $gameCategory = $this->findGameCategory($game, $category);
        $query = ListingQuery::fromRequest($request, $gameCategory);
        $result = $search->listing($query);

        $info = CatalogPresenter::gameCategory($gameCategory, $locale);
        $crumbs = [
            ['name' => __('dashboard.home'), 'href' => "/{$locale}"],
            ['name' => $gameCategory->type->translate('name', $locale), 'href' => "/{$locale}/catalog/{$gameCategory->type->urlSlug()}"],
            ['name' => $info['title'], 'href' => $gameCategory->path($locale)],
        ];

        return Inertia::render('Public/Catalog/Listing', [
            'gameCategory' => $info,
            'tabs' => $this->reader->categoryTabs($gameCategory->game, $locale, $gameCategory->id),
            'breadcrumbs' => $crumbs,
            'filters' => $this->filters($gameCategory, $locale, $result->facets, $result->bounds),
            'query' => [
                'q' => $query->text,
                'facets' => $query->facets,
                'ranges' => $query->ranges,
                'price' => [
                    'min' => $query->priceMin === null ? null : $query->priceMin / 100,
                    'max' => $query->priceMax === null ? null : $query->priceMax / 100,
                ],
                'sort' => $query->sort,
            ],
            'sorts' => ListingQuery::SORTS,
            'results' => [
                'items' => array_map(fn (Product $p): array => CatalogPresenter::productCard($p, $locale), $result->items),
                'total' => $result->total,
                'page' => $result->page,
                'lastPage' => $result->lastPage(),
            ],
            'seo' => Seo::make(
                $info['title'],
                __('catalog.listing.description', ['title' => $info['title'], 'count' => $result->total]),
                "/{$gameCategory->game->slug}/{$gameCategory->slug}",
                $locale,
                noindex: ! $query->isIndexable() || $gameCategory->product_count_cached === 0,
                jsonLd: [Seo::breadcrumbList($crumbs)],
            ),
        ]);
    }

    public function product(Request $request, string $locale, string $game, string $category, string $product): Response
    {
        $gameCategory = $this->findGameCategory($game, $category);

        $model = Product::query()
            ->active()
            ->where('game_category_id', $gameCategory->id)
            ->where('slug', $product)
            ->with(['platform', 'region'])
            ->first();
        abort_if($model === null, 404);
        $model->setRelation('gameCategory', $gameCategory);

        $name = $model->translate('name', $locale);
        $info = CatalogPresenter::gameCategory($gameCategory, $locale);
        $crumbs = [
            ['name' => __('dashboard.home'), 'href' => "/{$locale}"],
            ['name' => $info['title'], 'href' => $gameCategory->path($locale)],
            ['name' => $name, 'href' => $model->path($locale)],
        ];

        $productLd = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $name,
            'sku' => $model->public_id,
            'category' => $info['title'],
            'description' => mb_substr(strip_tags($model->translate('description', $locale)), 0, 500),
        ];

        return Inertia::render('Public/Catalog/Product', [
            'product' => [
                ...CatalogPresenter::productCard($model, $locale),
                'description' => $model->translate('description', $locale),
                'attributes' => CatalogPresenter::attributeList($model, $locale),
            ],
            'gameCategory' => $info,
            'gameCategoryHref' => $gameCategory->path($locale),
            'breadcrumbs' => $crumbs,
            'seo' => Seo::make(
                $name,
                $model->translate('description', $locale) ?: $name,
                "/{$gameCategory->game->slug}/{$gameCategory->slug}/{$model->slug}",
                $locale,
                jsonLd: [Seo::breadcrumbList($crumbs), $productLd],
            ),
        ]);
    }

    private function findGame(string $slug): Game
    {
        $game = Game::query()->enabled()->where('slug', $slug)->first();
        abort_if($game === null, 404);

        return $game;
    }

    private function findGameCategory(string $gameSlug, string $categorySlug): GameCategory
    {
        $gameCategory = GameCategory::query()
            ->visible()
            ->where('slug', $categorySlug)
            ->whereHas('game', fn (Builder $q) => $q->where('slug', $gameSlug))
            ->with(['game', 'type'])
            ->first();
        abort_if($gameCategory === null, 404);

        return $gameCategory;
    }

    /**
     * Filter sidebar model: platform, region and schema-defined attributes, each with disjunctive counts.
     *
     * @param  array<string, array<string, int>>  $facets
     * @param  array<string, array{min: float, max: float}>  $bounds
     * @return list<array<string, mixed>>
     */
    private function filters(GameCategory $gameCategory, string $locale, array $facets, array $bounds): array
    {
        $out = [];

        $platforms = Platform::query()->whereIn('slug', array_keys($facets['platform'] ?? []))->orderBy('sort')->get();
        if ($platforms->isNotEmpty()) {
            $out[] = [
                'key' => 'platform', 'param' => 'platform', 'type' => 'facet', 'label' => __('catalog.filter.platform'), 'unit' => null,
                'options' => $platforms->map(fn ($p): array => ['value' => $p->slug, 'label' => $p->name, 'count' => $facets['platform'][$p->slug] ?? 0])->all(),
            ];
        }

        $regions = Region::query()->whereIn('code', array_keys($facets['region'] ?? []))->orderBy('sort')->get();
        if ($regions->isNotEmpty()) {
            $out[] = [
                'key' => 'region', 'param' => 'region', 'type' => 'facet', 'label' => __('catalog.filter.region'), 'unit' => null,
                'options' => $regions->map(fn ($r): array => ['value' => $r->code, 'label' => trim(($r->flag ?? '').' '.$r->translate('name', $locale)), 'count' => $facets['region'][$r->code] ?? 0])->all(),
            ];
        }

        foreach ($gameCategory->attributeSchema()->filterable() as $field) {
            $key = $field['key'];
            $label = Translations::pick($field['label'], $locale);

            if ($field['type'] === AttributeType::Number) {
                if (isset($bounds[$key])) {
                    $out[] = ['key' => $key, 'param' => "r[{$key}]", 'type' => 'range', 'label' => $label, 'unit' => $field['unit'], 'bounds' => $bounds[$key], 'options' => []];
                }

                continue;
            }

            $counts = $facets["attr.{$key}"] ?? [];
            $options = $field['type'] === AttributeType::Boolean
                ? [['value' => 'yes', 'label' => __('catalog.yes')], ['value' => 'no', 'label' => __('catalog.no')]]
                : array_map(static fn (array $o): array => ['value' => $o['value'], 'label' => Translations::pick($o['label'], $locale)], $field['options']);

            $out[] = [
                'key' => "attr.{$key}",
                'param' => "f[{$key}]",
                'type' => 'facet',
                'label' => $label,
                'unit' => null,
                'options' => array_map(static fn (array $o): array => [...$o, 'count' => $counts[$o['value']] ?? 0], $options),
            ];
        }

        return $out;
    }
}
