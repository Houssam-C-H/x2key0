<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Content\Actions\RefreshTrending;
use App\Domains\Content\Models\HelpArticle;
use App\Domains\Content\Models\HomeModule;
use App\Domains\Content\Models\HomeModuleItem;
use App\Domains\Content\Models\NewsArticle;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Models\SeoLinkRow;
use App\Domains\Content\Support\ContentRenderer;
use App\Domains\Engagement\Models\BrowsingHistory;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPreference;
use App\Http\Controllers\BookmarkPromptController;
use Database\Factories\GameCategoryFactory;
use Database\Seeders\HomeModuleSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;

it('renders the 12 modules in order (defaults before seeding), and follows staff order and switches', function (): void {
    $this->get('/en')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Public/Home')
        ->where('modules', fn ($modules) => count($modules) === 11 && $modules[0]['code'] === 'hero')); // footer is drawn by the layout

    (new HomeModuleSeeder)->run();
    HomeModule::query()->where('code', 'game_news')->update(['sort' => 1]);
    HomeModule::query()->where('code', 'payment_strip')->update(['is_enabled' => false]);
    HomeModule::query()->where('code', 'hot_game')->update(['title' => json_encode(['en' => 'Popular right now'])]);

    $this->get('/en')->assertInertia(fn (Assert $p) => $p
        ->where('modules.0.code', 'game_news')
        ->where('modules', fn ($modules) => ! collect($modules)->pluck('code')->contains('payment_strip'))
        ->where('modules', fn ($modules) => collect($modules)->firstWhere('code', 'hot_game')['title'] === 'Popular right now'));
});

it('ranks trending categories by 7-day orders and views, and puts pinned items first', function (): void {
    (new HomeModuleSeeder)->run();
    GameCategoryFactory::type(CategoryTypeCode::TopUp);
    $quiet = GameCategory::factory()->ofType(CategoryTypeCode::TopUp)->create();
    $fx = CheckoutFixture::build(); // creates a top-up category with an offer
    $busy = $fx['manual']->gameCategory;

    $this->actingAs($fx['buyer']);
    CheckoutFixture::deliveredOrder($fx); // paid this week
    BrowsingHistory::query()->create(['user_id' => $fx['buyer']->id, 'offer_id' => $fx['manual']->id, 'viewed_at' => now()]);

    expect(app(RefreshTrending::class)->handle()['categories'])->toBe(1)
        ->and($busy->refresh()->trend_score)->toBe(RefreshTrending::ORDER_WEIGHT + 1)
        ->and($busy->game->refresh()->hot_score)->toBe(RefreshTrending::ORDER_WEIGHT + 1);

    $labels = fn () => collect($this->get('/en')->inertiaProps('modules'))->firstWhere('code', 'trending_topup')['data']['tiles'];
    expect($labels()[0]['href'])->toBe($busy->path('en'));

    $module = HomeModule::query()->where('code', 'trending_topup')->firstOrFail();
    HomeModuleItem::query()->create(['home_module_id' => $module->id, 'item_type' => 'game_category', 'item_id' => $quiet->id, 'pinned' => true, 'sort' => 0]);
    expect($labels()[0]['href'])->toBe($quiet->path('en'));

    // Old activity no longer counts.
    $this->travel(8)->days();
    app(RefreshTrending::class)->handle();
    expect($busy->refresh()->trend_score)->toBe(0);
});

it('shows the viewer\'s browsing history on the home page only for that viewer', function (): void {
    $fx = CheckoutFixture::build();
    BrowsingHistory::query()->create(['user_id' => $fx['buyer']->id, 'offer_id' => $fx['manual']->id, 'viewed_at' => now()]);
    $history = fn () => collect($this->get('/en')->inertiaProps('modules'))->firstWhere('code', 'browsing_history')['data']['items'];

    $this->actingAs($fx['buyer']);
    expect($history())->toHaveCount(1);
    $this->actingAs(User::factory()->create());
    expect($history())->toHaveCount(0);
});

it('lists published news only, shows an article, and 404s drafts and future posts', function (): void {
    (new HomeModuleSeeder)->run();
    $live = NewsArticle::query()->create(['slug' => 'live-news', 'title' => ['en' => 'Live'], 'excerpt' => ['en' => 'Short'], 'body' => ['en' => "## Hello\n\nBody"], 'status' => 'published', 'published_at' => now()->subHour()]);
    NewsArticle::query()->create(['slug' => 'draft-news', 'title' => ['en' => 'Draft'], 'body' => ['en' => 'x'], 'status' => 'draft', 'published_at' => now()->subHour()]);
    NewsArticle::query()->create(['slug' => 'later-news', 'title' => ['en' => 'Later'], 'body' => ['en' => 'x'], 'status' => 'published', 'published_at' => now()->addDay()]);

    $this->get('/en/news')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Public/Content/NewsIndex')->has('articles', 1)->where('articles.0.slug', 'live-news'));
    $this->get('/en/news/live-news')->assertOk()->assertInertia(fn (Assert $p) => $p->where('article.title', 'Live')->where('article.html', fn ($html) => str_contains($html, '<h2>Hello</h2>')));
    $this->get('/en/news/draft-news')->assertNotFound();
    $this->get('/en/news/later-news')->assertNotFound();

    expect(collect($this->get('/en')->inertiaProps('modules'))->firstWhere('code', 'game_news')['data']['articles'])->toHaveCount(1)
        ->and($live->path('en'))->toBe('/en/news/live-news');
});

it('renders CMS Markdown without raw HTML or script links', function (): void {
    $html = ContentRenderer::html("Hi <script>alert(1)</script>\n\n[click](javascript:alert(1)) and [ok](/en/help)");

    expect($html)->not->toContain('<script')->not->toContain('javascript:')->toContain('href="/en/help"');
});

it('shows help sections and articles, and CMS pages', function (): void {
    HelpArticle::query()->create(['slug' => 'pay-how', 'section' => 'payments', 'title' => ['en' => 'How to pay'], 'body' => ['en' => 'Card or credits.'], 'sort' => 0, 'is_published' => true]);
    HelpArticle::query()->create(['slug' => 'hidden', 'section' => 'faq', 'title' => ['en' => 'Hidden'], 'body' => ['en' => 'x'], 'is_published' => false]);
    Page::query()->create(['slug' => 'about', 'title' => ['en' => 'About us'], 'body' => ['en' => 'We are X2KEY.'], 'footer_column' => 'company', 'is_published' => true]);

    $this->get('/en/help')->assertOk()->assertInertia(fn (Assert $p) => $p->has('sections', 1)->where('sections.0.code', 'payments'));
    $this->get('/en/help/pay-how')->assertOk()->assertInertia(fn (Assert $p) => $p->where('article.section', 'payments'));
    $this->get('/en/help/hidden')->assertNotFound();
    $this->get('/en/page/about')->assertOk()->assertInertia(fn (Assert $p) => $p->where('kind', 'page')->where('article.title', 'About us'));
    $this->get('/en/page/nope')->assertNotFound();
});

it('fills the footer from CMS pages and SEO rows, and refreshes it when content changes', function (): void {
    Page::query()->create(['slug' => 'about', 'title' => ['en' => 'About us'], 'body' => ['en' => 'x'], 'footer_column' => 'company', 'is_published' => true]);
    SeoLinkRow::query()->create(['label' => ['en' => 'Buy gold'], 'url' => '/en/catalog/currency', 'sort' => 0, 'is_enabled' => true]);

    $this->get('/en')->assertInertia(fn (Assert $p) => $p
        ->where('footer.columns.company.0.label', 'About us')
        ->where('footer.seoLinks.0.label', 'Buy gold'));

    Page::query()->create(['slug' => 'terms', 'title' => ['en' => 'Terms'], 'body' => ['en' => 'x'], 'footer_column' => 'legal', 'is_published' => true]);
    $this->get('/en')->assertInertia(fn (Assert $p) => $p->where('footer.columns.legal.0.label', 'Terms'));

    // Dashboard pages carry no footer data.
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('footer', null));
});

it('shows the bookmark prompt until "No more prompts", for guests (cookie) and users (preference)', function (): void {
    $this->get('/en')->assertInertia(fn (Assert $p) => $p->where('bookmarkPrompt', true));
    $this->withCookie(BookmarkPromptController::COOKIE, '1')->get('/en')->assertInertia(fn (Assert $p) => $p->where('bookmarkPrompt', false));

    $user = User::factory()->create();
    $this->actingAs($user)->post('/bookmark-prompt/dismiss')->assertRedirect()->assertCookie(BookmarkPromptController::COOKIE);
    expect(UserPreference::query()->where('user_id', $user->id)->value('bookmark_prompt_dismissed'))->toBeTrue();
});

it('lists payment methods in the payment strip', function (): void {
    (new PaymentMethodSeeder)->run();
    $strip = collect($this->get('/en')->inertiaProps('modules'))->firstWhere('code', 'payment_strip');

    expect($strip['data']['methods'])->not->toBeEmpty();
});

it('adds news, help and pages to the sitemap', function (): void {
    NewsArticle::query()->create(['slug' => 'live-news', 'title' => ['en' => 'Live'], 'body' => ['en' => 'x'], 'status' => 'published', 'published_at' => now()->subHour()]);
    Page::query()->create(['slug' => 'about', 'title' => ['en' => 'About'], 'body' => ['en' => 'x'], 'is_published' => true]);

    $this->get('/sitemap-en.xml')->assertOk()->assertSee('/en/news/live-news', false)->assertSee('/en/page/about', false)->assertSee('/en/help', false);
});

it('opens the content admin to moderators and support, not to finance', function (): void {
    (new HomeModuleSeeder)->run();
    $this->actingAs(User::factory()->staff(StaffRole::Moderator)->withTwoFactor()->create());
    foreach (['/admin/home-modules', '/admin/home-pins', '/admin/home-pins/create', '/admin/news', '/admin/news/create', '/admin/help-articles', '/admin/pages', '/admin/seo-links'] as $url) {
        $this->get($url)->assertOk();
    }
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create())->get('/admin/news')->assertForbidden();
});
