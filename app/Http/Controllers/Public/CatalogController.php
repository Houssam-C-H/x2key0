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
use App\Domains\Catalog\Models\ServiceType;
use App\Domains\Catalog\Search\CatalogSearch;
use App\Domains\Catalog\Search\ListingQuery;
use App\Domains\Catalog\Support\CatalogPresenter;
use App\Domains\Catalog\Support\CatalogReader;
use App\Domains\Engagement\Actions\RecordView;
use App\Domains\Engagement\Models\Favorite;
use App\Domains\Engagement\Support\EngagementReader;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Support\OfferPresenter;
use App\Http\Controllers\EngagementController;
use App\Http\Support\Seo;
use App\Shared\Support\Translations;
use Brick\Money\Money;
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
    private const int OFFERS_PER_PAGE = 24;

    private const int PRODUCT_OFFERS_MAX = 100;

    public function __construct(
        private readonly CatalogReader $reader,
        private readonly EngagementReader $engagement,
        private readonly RecordView $recordView,
    ) {}

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
            'game' => ['name' => $name, 'slug' => $model->slug, 'publisher' => $model->publisher, 'icon' => $model->iconUrl(), 'banner' => $model->heroUrl()],
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

        // Unit categories (currency, accounts, boosting…) have no catalog products: list offer cards (spec 1.4.1).
        $offerResults = null;
        $serviceTypes = [];
        $activeService = null;
        if (! Product::query()->active()->where('game_category_id', $gameCategory->id)->exists()) {
            // Service type chips (ADR-032): enabled services of this category type, with live offer counts here.
            $available = ServiceType::availableFor($gameCategory->category_type_id);
            if ($available->isNotEmpty()) {
                $counts = [];
                $rows = Offer::query()->live()->where('offers.game_category_id', $gameCategory->id)
                    ->whereNotNull('offers.service_type_id')
                    ->groupBy('offers.service_type_id')
                    ->selectRaw('offers.service_type_id as sid, COUNT(*) as aggregate')
                    ->toBase()->get();
                foreach ($rows as $row) {
                    $sid = $row->sid ?? null;
                    $count = $row->aggregate ?? null;
                    if (is_numeric($sid) && is_numeric($count)) {
                        $counts[(int) $sid] = (int) $count;
                    }
                }
                $activeService = $available->firstWhere('code', $request->string('service')->toString());
                $serviceTypes = array_values($available->map(static fn (ServiceType $s): array => [
                    'code' => $s->code,
                    'name' => $s->translate('name', $locale),
                    'count' => $counts[$s->id] ?? 0,
                ])->all());
            }
            $activeServiceId = $activeService?->id;

            $page = Offer::query()->live()->where('offers.game_category_id', $gameCategory->id)
                ->when($activeServiceId !== null, fn (Builder $q) => $q->where('offers.service_type_id', $activeServiceId))
                ->when($query->text !== '', fn (Builder $q) => $q->where('offers.title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query->text).'%'))
                ->recommended()
                ->with(OfferPresenter::publicRelations())
                ->paginate(self::OFFERS_PER_PAGE)
                ->withQueryString();
            $offerResults = [
                'items' => array_map(fn (Offer $o): array => OfferPresenter::card($o, $locale), $page->items()),
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
            ];
        }

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
            'offerResults' => $offerResults,
            'serviceTypes' => $serviceTypes,
            'activeService' => $activeService?->code,
            'seo' => Seo::make(
                $info['title'],
                __('catalog.listing.description', ['title' => $info['title'], 'count' => $result->total]),
                "/{$gameCategory->game->slug}/{$gameCategory->slug}",
                $locale,
                noindex: ! $query->isIndexable() || ($gameCategory->product_count_cached === 0 && $gameCategory->offer_count_cached === 0),
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

        $viewer = $request->user();
        $viewerId = $viewer instanceof User ? $viewer->id : null;

        // Offers table (spec 1.5): recommended order; the first row is the buy box.
        $offers = Offer::query()->live()->where('offers.product_id', $model->id)
            ->recommended()
            ->with(OfferPresenter::publicRelations())
            ->limit(self::PRODUCT_OFFERS_MAX)
            ->get();
        $favorited = $this->engagement->favoritedIds($viewerId, Favorite::OFFER, array_values($offers->map(static fn (Offer $o): int => $o->id)->all()));
        $rows = $offers->map(fn (Offer $o): array => OfferPresenter::row($o, $locale, $favorited))->values()->all();

        $guest = $viewerId === null ? EngagementController::guestToken($request, true) : null;
        $history = $this->engagement->history($viewerId, $guest, $locale);
        $this->recordView->handle($viewerId, $guest, $model->id, null);

        $productLd = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $name,
            'sku' => $model->public_id,
            'category' => $info['title'],
            'description' => mb_substr(strip_tags($model->translate('description', $locale)), 0, 500),
        ];

        if ($offers->isNotEmpty()) {
            $productLd['offers'] = [
                '@type' => 'AggregateOffer',
                'priceCurrency' => 'USD',
                'lowPrice' => self::decimal($offers->min('unit_price_amount')),
                'highPrice' => self::decimal($offers->max('unit_price_amount')),
                'offerCount' => $offers->count(),
            ];
        }

        return Inertia::render('Public/Catalog/Product', [
            'product' => [
                ...CatalogPresenter::productCard($model, $locale),
                'description' => $model->translate('description', $locale),
                'attributes' => CatalogPresenter::attributeList($model, $locale),
            ],
            'gameCategory' => $info,
            'gameCategoryHref' => $gameCategory->path($locale),
            'offers' => $rows,
            'deliveryMethods' => array_values(array_unique($offers->map(static fn (Offer $o): string => $o->delivery_method->value)->all())),
            'isFavorite' => $this->engagement->favoritedIds($viewerId, Favorite::PRODUCT, [$model->id]) !== [],
            'history' => array_values(array_filter($history, static fn (array $h): bool => ($h['id'] ?? null) !== $model->public_id)),
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

    /**
     * Single offer page /{locale}/offer/{publicId} (spec 1.5): buy box without the other-sellers panel.
     * The owner may preview an offer that is not public (noindex, with a banner).
     */
    public function offer(Request $request, string $locale, string $publicId): Response
    {
        $model = Offer::query()->where('public_id', $publicId)->with(OfferPresenter::publicRelations())->first();
        abort_if($model === null, 404);

        $viewer = $request->user();
        $viewerId = $viewer instanceof User ? $viewer->id : null;
        $isLive = Offer::query()->live()->whereKey($model->id)->exists();
        $isOwner = $viewerId !== null && $model->seller->user_id === $viewerId;
        abort_unless($isLive || $isOwner, 404);

        $gameCategory = $model->gameCategory;
        $info = CatalogPresenter::gameCategory($gameCategory, $locale);
        $crumbs = [
            ['name' => __('dashboard.home'), 'href' => "/{$locale}"],
            ['name' => $info['title'], 'href' => $gameCategory->path($locale)],
            ['name' => $model->title, 'href' => $model->path($locale)],
        ];

        $guest = $viewerId === null ? EngagementController::guestToken($request, true) : null;
        $history = $this->engagement->history($viewerId, $guest, $locale);
        if ($isLive) {
            $this->recordView->handle($viewerId, $guest, null, $model->id);
        }

        $favorited = $this->engagement->favoritedIds($viewerId, Favorite::OFFER, [$model->id]);
        $description = $model->descriptionText($locale);

        return Inertia::render('Public/Catalog/Offer', [
            'offer' => OfferPresenter::detail($model, $locale, $favorited),
            'preview' => $isLive ? null : $model->status->value,
            'history' => array_values(array_filter($history, static fn (array $h): bool => ($h['id'] ?? null) !== $model->public_id)),
            'breadcrumbs' => $crumbs,
            'seo' => Seo::make(
                $model->title,
                $description !== '' ? mb_substr($description, 0, 300) : $model->title,
                "/offer/{$model->public_id}",
                $locale,
                noindex: ! $isLive,
                jsonLd: [Seo::breadcrumbList($crumbs), [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $model->title,
                    'sku' => $model->public_id,
                    'category' => $info['title'],
                    'offers' => [
                        '@type' => 'Offer',
                        'priceCurrency' => 'USD',
                        'price' => self::decimal($model->unit_price_amount),
                        'availability' => $model->stock() > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                        'seller' => ['@type' => 'Organization', 'name' => $model->seller->store_name],
                    ],
                ]],
            ),
        ]);
    }

    /** Integer cents → "12.34" for structured data, without floats. */
    private static function decimal(mixed $cents): string
    {
        return (string) Money::ofMinor(is_numeric($cents) ? (int) $cents : 0, 'USD')->getAmount();
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
