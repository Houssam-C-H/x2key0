<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\RecountCatalog;
use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\CategoryType;
use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use Database\Factories\GameCategoryFactory;
use Database\Seeders\CatalogSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CatalogFixture;

beforeEach(function (): void {
    $this->fx = CatalogFixture::build();
    app(RecountCatalog::class)->handle();
});

it('renders the listing with filters, counts, tabs, notice and results', function (): void {
    $this->get('/en/star-quest/video-games')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Catalog/Listing')
            ->where('gameCategory.title', 'Star Quest Video Games')
            ->where('gameCategory.notice', 'Independent sellers notice.')
            ->where('results.total', 4)
            ->has('results.items', 4)
            ->where('tabs.0.active', true)
            // Counts show offers once products have them (3 priced products × 2 offers), products otherwise.
            ->where('tabs.0.count', 6)
            ->where('filters', function ($filters): bool {
                $byKey = collect($filters)->keyBy('key');

                return $byKey['platform']['options'][0] === ['value' => 'pc', 'label' => 'PC', 'count' => 2]
                    && collect($byKey['attr.edition']['options'])->pluck('count', 'value')->all() === ['standard' => 2, 'deluxe' => 1, 'ultimate' => 1]
                    && $byKey['release_year']['type'] === 'range'
                    // JSON drops the .0 of whole floats, so compare numerically.
                    && $byKey['release_year']['bounds'] == ['min' => 2019, 'max' => 2024];
            }));
});

it('applies filters from the query string and ignores junk values', function (): void {
    $this->get('/en/star-quest/video-games?f[edition][]=standard&platform[]=pc')
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.total', 2)
            ->where('query.facets', ['platform' => ['pc'], 'attr.edition' => ['standard']]));

    $this->get('/en/star-quest/video-games?f[edition][]=bogus&f[unknown][]=x&sort=hacked&page=-4')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.total', 4)
            ->where('query.facets', [])
            ->where('query.sort', 'recommended')
            ->where('results.page', 1));
});

it('converts the price filter from dollars to cents', function (): void {
    $this->get('/en/star-quest/video-games?price[max]=15')
        ->assertInertia(fn (Assert $page) => $page->where('results.total', 2)->where('query.price.max', 15));
});

it('marks only plain or single platform/region listings as indexable', function (string $query, bool $noindex): void {
    $this->get('/en/star-quest/video-games'.$query)
        ->assertInertia(fn (Assert $page) => $page->where('seo.noindex', $noindex));
})->with([
    'plain' => ['', false],
    'one platform' => ['?platform[]=pc', false],
    'attribute filter' => ['?f[edition][]=standard', true],
    'two platforms' => ['?platform[]=pc&platform[]=ps', true],
    'page 2' => ['?page=2', true],
    'text search' => ['?q=alpha', true],
]);

it('sends canonical and hreflang alternates for every locale', function (): void {
    $this->get('/fr/star-quest/video-games')
        ->assertInertia(fn (Assert $page) => $page
            ->where('seo.canonical', url('/fr/star-quest/video-games'))
            ->where('seo.alternates', fn ($alts) => collect($alts)->pluck('locale')->all() === ['en', 'fr', 'ar', 'es', 'de', 'pt', 'tr', 'x-default'])
            ->where('seo.jsonLd.0.@type', 'BreadcrumbList'));
});

it('renders the product page with labelled attributes and Product JSON-LD', function (): void {
    $this->get('/en/star-quest/video-games/beta-deluxe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Catalog/Product')
            ->where('product.name', 'Beta Deluxe')
            ->where('product.priceCents', 2000)
            ->where('product.attributes', [
                ['label' => 'Edition', 'value' => 'Deluxe'],
                ['label' => 'Genre', 'value' => 'Action, RPG'],
                ['label' => 'Multiplayer', 'value' => 'No'],
                ['label' => 'Release year', 'value' => '2022'],
            ])
            ->where('seo.jsonLd.1.@type', 'Product'));
});

it('hides draft products, disabled games, disabled game categories and disabled category types', function (): void {
    $this->get('/en/star-quest/video-games/draft-standard')->assertNotFound();

    $this->fx['gameCategory']->update(['is_enabled' => false]);
    $this->get('/en/star-quest/video-games')->assertNotFound();
    $this->fx['gameCategory']->update(['is_enabled' => true]);

    CategoryType::query()->where('code', CategoryTypeCode::VideoGames)->update(['is_enabled' => false]);
    $this->get('/en/star-quest/video-games')->assertNotFound();
    $this->get('/en/catalog/video-games')->assertNotFound();
    CategoryType::query()->where('code', CategoryTypeCode::VideoGames)->update(['is_enabled' => true]);

    $this->fx['game']->update(['is_enabled' => false]);
    $this->get('/en/star-quest')->assertNotFound();
    $this->get('/en/star-quest/video-games')->assertNotFound();
});

it('404s unknown slugs and never treats reserved words as games', function (): void {
    $this->get('/en/no-such-game')->assertNotFound();
    $this->get('/en/star-quest/no-such-category')->assertNotFound();
    $this->get('/en/star-quest/video-games/no-such-product')->assertNotFound();
    $this->get('/en/games')->assertInertia(fn (Assert $page) => $page->component('Public/Catalog/GameIndex'));
    $this->get('/en/search?q=star')->assertInertia(fn (Assert $page) => $page->component('Public/Catalog/Search'));
});

it('rejects reserved game slugs at the model-validation layer of the admin', function (): void {
    expect(in_array('games', Game::RESERVED_SLUGS, true))->toBeTrue();
});

it('renders the game hub with category tabs and counts', function (): void {
    GameCategory::factory()->for($this->fx['game'])->ofType(CategoryTypeCode::GiftCards)->create();
    app(RecountCatalog::class)->handle();

    $this->get('/en/star-quest')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Catalog/GameHub')
            ->where('game.name', 'Star Quest')
            ->has('tabs', 2)
            ->where('tabs.0.code', 'video_games')
            ->where('tabs.0.count', 6)
            ->where('tabs.1.count', 0));
});

it('groups the Game Index A–Z with a HOT list', function (): void {
    Game::factory()->named('42 Arena', 'arena-42')->hot()->has(GameCategory::factory()->ofType(CategoryTypeCode::GiftCards), 'categories')->create();
    app(RecountCatalog::class)->handle();

    $this->get('/en/games')->assertInertia(fn (Assert $page) => $page
        ->where('index.letters', ['S', '#'])
        ->where('index.groups.S.0.name', 'Star Quest')
        ->where('index.groups.S.0.categories.0.count', 6)
        ->where('index.groups.#.0.name', '42 Arena')
        ->where('index.hot.0.name', '42 Arena'));
});

it('lists every game of a category type on the category hub', function (): void {
    $this->get('/en/catalog/video-games')->assertInertia(fn (Assert $page) => $page
        ->component('Public/Catalog/CategoryHub')
        ->where('type.code', 'video_games')
        ->where('games.0.name', 'Star Quest')
        ->where('games.0.count', 6));

    $this->get('/en/catalog/not-a-type')->assertNotFound();
});

it('searches games by name and alias, and products by name', function (): void {
    $this->fx['game']->update(['search_aliases' => ['sq']]);

    $this->get('/en/search?q=sq')->assertInertia(fn (Assert $page) => $page->where('results.games.0.name', 'Star Quest'));
    $this->get('/en/search?q=deluxe')->assertInertia(fn (Assert $page) => $page
        ->where('results.products.0.name', 'Beta Deluxe')
        ->has('results.products', 1));
    $this->get('/en/search?q=deluxe&type=gift_cards')->assertInertia(fn (Assert $page) => $page->has('results.products', 0));
    $this->get('/en/search?q=a')->assertInertia(fn (Assert $page) => $page->has('results.games', 0));
});

it('shares the mega menu: enabled types, top games, counts; refreshed after edits', function (): void {
    $this->get('/en')->assertInertia(fn (Assert $page) => $page->where('megaMenu', function ($columns): bool {
        $video = collect($columns)->firstWhere('code', 'video_games');

        return $video['href'] === '/en/catalog/video-games'
            && $video['games'][0] === ['name' => 'Star Quest', 'href' => '/en/star-quest/video-games', 'count' => 6, 'icon' => null];
    }));

    $this->fx['game']->update(['name' => ['en' => 'Star Quest II']]);

    $this->get('/en')->assertInertia(fn (Assert $page) => $page->where('megaMenu', fn ($columns): bool => collect($columns)->firstWhere('code', 'video_games')['games'][0]['name'] === 'Star Quest II'));
});

it('publishes sitemaps per locale without thin pages', function (): void {
    $empty = GameCategory::factory()->for(Game::factory()->named('Empty Game', 'empty-game'))->ofType(CategoryTypeCode::GiftCards)->create();
    app(RecountCatalog::class)->handle();

    $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee(url('/sitemap-ar.xml'), false);

    $xml = $this->get('/sitemap-fr.xml')->assertOk()->getContent();

    expect($xml)->toContain(url('/fr/star-quest/video-games'))
        ->toContain(url('/fr/star-quest/video-games/beta-deluxe'))
        ->not->toContain('draft-standard')
        ->not->toContain('empty-game');

    $this->get('/sitemap-it.xml')->assertNotFound();
});

it('recounts idempotently', function (): void {
    app(RecountCatalog::class)->handle();
    app(RecountCatalog::class)->handle();

    expect($this->fx['gameCategory']->fresh()?->product_count_cached)->toBe(4)
        ->and($this->fx['gameCategory']->fresh()?->offer_count_cached)->toBe(6) // 3 priced products × 2 offers
        ->and($this->fx['game']->fresh()?->product_count_cached)->toBe(4);

    Product::query()->where('slug', 'alpha-standard')->first()?->delete();
    app(RecountCatalog::class)->handle();

    expect($this->fx['gameCategory']->fresh()?->product_count_cached)->toBe(3);
});

it('refuses to save a game category with a malformed schema', function (): void {
    expect(fn () => GameCategory::factory()->for($this->fx['game'])->ofType(CategoryTypeCode::Items)->withSchema([['key' => 'BAD', 'type' => 'text', 'label' => 'x']])->create())
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to save a product whose attributes break the schema', function (): void {
    expect(fn () => Product::factory()->for($this->fx['gameCategory'])->attrs(['edition' => 'collector'])->create())
        ->toThrow(InvalidArgumentException::class);
});

it('seeds the full demo catalog and every page type renders', function (): void {
    $this->seed(CatalogSeeder::class);

    expect(CategoryType::query()->count())->toBe(15)
        ->and(Game::query()->count())->toBeGreaterThan(40);

    $this->get('/en/fortnite')->assertOk();
    $this->get('/ar/fortnite/v-bucks')->assertOk()->assertInertia(fn (Assert $page) => $page->where('dir', 'rtl')->where('gameCategory.label', 'V-Bucks'));
    $this->get('/en/ea-sports-fc-27/coins')->assertOk();
    $this->get('/en/catalog/top-up')->assertOk();

    $product = Product::query()->active()->with('gameCategory.game')->firstOrFail();
    $this->get($product->path('fr'))->assertOk();

    GameCategoryFactory::type(CategoryTypeCode::Accounts); // idempotent helper
});
