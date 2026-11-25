<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Game;
use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Auth\AuthPageController;
use App\Http\Controllers\BookmarkPromptController;
use App\Http\Controllers\Buy\CommentController;
use App\Http\Controllers\Buy\FinanceController;
use App\Http\Controllers\Buy\PurchaseOrderController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\EngagementController;
use App\Http\Controllers\FakePspController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\OpsController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\Public\AffiliateLandingController;
use App\Http\Controllers\Public\CatalogController;
use App\Http\Controllers\Public\ContentController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\SitemapController;
use App\Http\Controllers\Public\StoreController;
use App\Http\Controllers\Seller\CampaignController;
use App\Http\Controllers\Seller\CommentController as SellerCommentController;
use App\Http\Controllers\Seller\CouponController as SellerCouponController;
use App\Http\Controllers\Seller\MarketingEmailController;
use App\Http\Controllers\Seller\MerchantContactsController;
use App\Http\Controllers\Seller\OfferController;
use App\Http\Controllers\Seller\OnboardingController;
use App\Http\Controllers\Seller\SellerInfoController;
use App\Http\Controllers\Seller\ServiceApplicationController;
use App\Http\Controllers\Seller\SoldOrderController;
use App\Http\Controllers\Seller\StockAlertController;
use App\Http\Controllers\Seller\StorageController;
use App\Http\Controllers\Seller\StoreSettingsController;
use App\Http\Controllers\Seller\ViolationController;
use App\Http\Controllers\Seller\WithdrawalController;
use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\CertificationController;
use App\Http\Controllers\Settings\DeleteAccountController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\TicketController;
use App\Http\Middleware\ResolveLocale;
use App\Shared\Navigation\DashboardMenu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$locales = implode('|', ResolveLocale::supportedLocales());

// Bare domain → the visitor's preferred locale.
Route::get('/', fn (Request $request) => redirect('/'.ResolveLocale::preferredLocale($request)))
    ->name('root');

Route::post('/preferences', PreferencesController::class)->name('preferences');

// Uptime monitoring (docs/RUNBOOK.md) and crawler rules (spec 22: SEO).
Route::get('/health', [OpsController::class, 'health'])->middleware('throttle:60,1')->name('ops.health');
Route::get('/robots.txt', function () {
    $lines = ['User-agent: *'];
    foreach (['/admin', '/dashboard', '/buy/', '/seller/', '/settings/', '/chat', '/checkout', '/announcements', '/disputes', '/tickets', '/affiliate/', '/coupons/', '/invoices', '/r/', '/fake-psp/', '/webhooks/', '/health'] as $path) {
        $lines[] = 'Disallow: '.$path;
    }
    $lines[] = '';
    $lines[] = 'Sitemap: '.url('/sitemap.xml');

    return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->name('robots');
Route::post('/bookmark-prompt/dismiss', BookmarkPromptController::class)->middleware('throttle:20,1')->name('bookmark.dismiss');

// Affiliate referral links (spec Part 15 `/r/{code}`): count the click, set the first-click cookie, go on.
Route::get('/r/{code}/{slug?}', [AffiliateLandingController::class, 'redirect'])->where(['code' => '[A-Za-z0-9]{4,16}', 'slug' => '[a-z0-9]{10}'])->middleware('throttle:120,1')->name('affiliate.redirect');

// PSP webhooks (spec 9.3): signature-authenticated, CSRF-exempt (bootstrap/app.php).
Route::post('/webhooks/{provider}', PaymentWebhookController::class)->where('provider', '[a-z0-9_]+')->middleware('throttle:600,1')->name('webhooks.payments');

// Fake PSP hosted page (dev/test only; the controller returns 404 in production).
Route::get('/fake-psp/{id}', [FakePspController::class, 'show'])->where('id', 'fake_[a-z0-9]{26}')->name('fake-psp.show');
Route::post('/fake-psp/{id}/{outcome}', [FakePspController::class, 'complete'])->where(['id' => 'fake_[a-z0-9]{26}', 'outcome' => 'pay|fail|cancel'])->name('fake-psp.complete');

// Browsing history removal works for signed-in users and guests (cookie token) — spec Part 20.
Route::delete('/history', [EngagementController::class, 'clear'])->name('history.clear');
Route::delete('/history/{id}', [EngagementController::class, 'forget'])->whereNumber('id')->name('history.forget');

/*
| Public pages (SSR, localized) — spec Part 20. Catalog, product, store, news and help
| routes are added by their milestones (M3+).
*/
Route::prefix('{locale}')
    ->where(['locale' => $locales])
    ->group(function (): void {
        Route::get('/', HomeController::class)->name('home');

        // GET pages for Fortify's backend (POST /login, /register, … are registered by Fortify).
        Route::middleware('guest')->group(function (): void {
            Route::get('/login', [AuthPageController::class, 'login'])->name('login');
            Route::get('/register', [AuthPageController::class, 'register'])->name('register');
            Route::get('/forgot-password', [AuthPageController::class, 'forgotPassword'])->name('password.request');
            Route::get('/reset-password/{token}', [AuthPageController::class, 'resetPassword'])->name('password.reset');
        });

        // Catalog (spec 1.4, Part 20). Fixed paths first; game/category/product slugs last.
        Route::get('/games', [CatalogController::class, 'games'])->name('catalog.games');
        Route::get('/catalog/{type}', [CatalogController::class, 'categoryHub'])->where('type', '[a-z-]+')->name('catalog.type');
        Route::get('/search', [CatalogController::class, 'search'])->name('catalog.search');
        Route::get('/affiliate', [AffiliateLandingController::class, 'show'])->name('affiliate.landing');

        // News, Help center, CMS pages (spec Part 20)
        Route::get('/news', [ContentController::class, 'news'])->name('news.index');
        Route::get('/news/{slug}', [ContentController::class, 'article'])->where('slug', '[a-z0-9][a-z0-9-]*')->name('news.show');
        Route::get('/help', [ContentController::class, 'help'])->name('help.index');
        Route::get('/help/{slug}', [ContentController::class, 'helpArticle'])->where('slug', '[a-z0-9][a-z0-9-]*')->name('help.show');
        Route::get('/page/{slug}', [ContentController::class, 'page'])->where('slug', '[a-z0-9][a-z0-9-]*')->name('page.show');
        Route::get('/store/{slug}', [StoreController::class, 'show'])->where('slug', '[a-z0-9][a-z0-9-]*')->name('store.show');
        Route::get('/offer/{publicId}', [CatalogController::class, 'offer'])->where('publicId', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('offer.show');

        $gameSlug = '(?!(?:'.implode('|', array_map('preg_quote', Game::RESERVED_SLUGS)).')$)[a-z0-9][a-z0-9-]*';
        $slug = '[a-z0-9][a-z0-9-]*';

        Route::get('/{game}', [CatalogController::class, 'game'])->where('game', $gameSlug)->name('catalog.game');
        Route::get('/{game}/{category}', [CatalogController::class, 'listing'])->where(['game' => $gameSlug, 'category' => $slug])->name('catalog.listing');
        Route::get('/{game}/{category}/{product}', [CatalogController::class, 'product'])->where(['game' => $gameSlug, 'category' => $slug, 'product' => $slug])->name('catalog.product');
    });

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemap-{locale}.xml', [SitemapController::class, 'locale'])->where('locale', $locales)->name('sitemap.locale');

Route::get('/two-factor-challenge', [AuthPageController::class, 'twoFactorChallenge'])
    ->middleware('guest')
    ->name('two-factor.login');

/*
| Dashboard (not localized; locale from cookie — ADR-005).
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/email/verify', [AuthPageController::class, 'verifyEmail'])->name('verification.notice');
    Route::get('/user/confirm-password', [AuthPageController::class, 'confirmPassword'])->name('password.confirm');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Screens that exist. They keep the `dash.{menu key}` name of their menu entry (ADR-004).
    /** @var array<string, Closure(): Illuminate\Routing\Route> $implemented */
    $implemented = [
        '/settings/account' => fn () => Route::get('/settings/account', [AccountController::class, 'show'])->name('dash.settings.account'),
        '/settings/security' => fn () => Route::get('/settings/security', [SecurityController::class, 'show'])->name('dash.settings.security'),
        '/settings/certification' => fn () => Route::get('/settings/certification', [CertificationController::class, 'show'])->name('dash.settings.certification'),
        '/seller/store' => fn () => Route::get('/seller/store', [StoreSettingsController::class, 'show'])->name('dash.seller.store.mine'),
        '/seller/services' => fn () => Route::get('/seller/services', [ServiceApplicationController::class, 'index'])->name('dash.seller.store.services'),
        '/seller/info' => fn () => Route::get('/seller/info', [SellerInfoController::class, 'show'])->name('dash.seller.info'),
        '/seller/contacts' => fn () => Route::get('/seller/contacts', MerchantContactsController::class)->name('dash.seller.contacts'),
        '/seller/offers' => fn () => Route::get('/seller/offers', [OfferController::class, 'index'])->name('dash.seller.offers'),
        '/seller/offers/create' => fn () => Route::get('/seller/offers/create', [OfferController::class, 'create'])->name('dash.seller.offers.create'),
        '/buy/orders' => fn () => Route::get('/buy/orders', [PurchaseOrderController::class, 'index'])->name('dash.buy.orders'),
        '/seller/orders' => fn () => Route::get('/seller/orders', [SoldOrderController::class, 'index'])->name('dash.seller.orders'),
        '/seller/storage/logs' => fn () => Route::get('/seller/storage/logs', [StorageController::class, 'logs'])->name('dash.storage.logs'),
        '/buy/credits' => fn () => Route::get('/buy/credits', [FinanceController::class, 'credits'])->name('dash.buy.credits'),
        '/buy/points' => fn () => Route::get('/buy/points', [FinanceController::class, 'points'])->name('dash.buy.points'),
        '/buy/funding' => fn () => Route::get('/buy/funding', [FinanceController::class, 'funding'])->name('dash.buy.funding'),
        '/announcements' => fn () => Route::get('/announcements', [AnnouncementController::class, 'index'])->name('dash.announcements.all'),
        '/coupons/shopping' => fn () => Route::get('/coupons/shopping', [CouponController::class, 'shopping'])->name('dash.coupons.shopping'),
        '/coupons/platform' => fn () => Route::get('/coupons/platform', [CouponController::class, 'platform'])->name('dash.coupons.platform'),
        '/seller/coupons' => fn () => Route::get('/seller/coupons', [SellerCouponController::class, 'index'])->name('dash.seller.store.coupons'),
        '/seller/campaigns' => fn () => Route::get('/seller/campaigns', [CampaignController::class, 'index'])->name('dash.seller.marketing.campaigns'),
        '/seller/marketing/emails' => fn () => Route::get('/seller/marketing/emails', [MarketingEmailController::class, 'create'])->name('dash.seller.marketing.emails'),
        '/seller/marketing/records' => fn () => Route::get('/seller/marketing/records', [MarketingEmailController::class, 'records'])->name('dash.seller.marketing.records'),
        '/seller/coupons/private' => fn () => Route::get('/seller/coupons/private', [SellerCouponController::class, 'private'])->name('dash.seller.store.coupons_private'),
        '/affiliate/manage' => fn () => Route::get('/affiliate/manage', [AffiliateController::class, 'manage'])->name('dash.affiliate.manage'),
        '/affiliate/referrals' => fn () => Route::get('/affiliate/referrals', [AffiliateController::class, 'referrals'])->name('dash.affiliate.referrals'),
        '/affiliate/videos' => fn () => Route::get('/affiliate/videos', [AffiliateController::class, 'videos'])->name('dash.affiliate.videos'),
        '/affiliate/bills' => fn () => Route::get('/affiliate/bills', [AffiliateController::class, 'bills'])->name('dash.affiliate.bills'),
        '/affiliate/assets' => fn () => Route::get('/affiliate/assets', [AffiliateController::class, 'assets'])->name('dash.affiliate.assets'),
        '/buy/comments' => fn () => Route::get('/buy/comments', [CommentController::class, 'index'])->name('dash.buy.comments'),
        '/seller/comments' => fn () => Route::get('/seller/comments', [SellerCommentController::class, 'index'])->name('dash.seller.store.comments'),
        '/settings/delete-account' => fn () => Route::get('/settings/delete-account', [DeleteAccountController::class, 'show'])->name('dash.settings.delete'),
        '/buy/favorites' => fn () => Route::get('/buy/favorites', [EngagementController::class, 'favorites'])->name('dash.buy.favorites'),
        '/seller/withdrawals' => fn () => Route::get('/seller/withdrawals', [WithdrawalController::class, 'index'])->name('dash.seller.withdrawals.records'),
        '/seller/withdrawals/methods' => fn () => Route::get('/seller/withdrawals/methods', [WithdrawalController::class, 'methods'])->name('dash.seller.withdrawals.methods'),
        '/invoices/service' => fn () => Route::get('/invoices/service', [InvoiceController::class, 'service'])->name('dash.invoices.service'),
        '/invoices/vouchers' => fn () => Route::get('/invoices/vouchers', [InvoiceController::class, 'vouchers'])->name('dash.invoices.vouchers'),
        '/invoices/info' => fn () => Route::get('/invoices/info', [InvoiceController::class, 'info'])->name('dash.invoices.info'),
        '/tickets' => fn () => Route::get('/tickets', [TicketController::class, 'index'])->name('dash.tickets.all'),
        '/disputes' => fn () => Route::get('/disputes', [DisputeController::class, 'index'])->name('dash.disputes.index'),
        '/disputes/stats' => fn () => Route::get('/disputes/stats', [DisputeController::class, 'stats'])->name('dash.disputes.stats'),
        '/seller/violations' => fn () => Route::get('/seller/violations', [ViolationController::class, 'index'])->name('dash.seller.violations'),
        // Storage pages get the Privacy Password gate below (spec 1.7).
        '/seller/storage' => fn () => Route::get('/seller/storage', [StorageController::class, 'groups'])->name('dash.storage.groups'),
        '/seller/storage/items' => fn () => Route::get('/seller/storage/items', [StorageController::class, 'items'])->name('dash.storage.items'),
        '/seller/storage/recycle' => fn () => Route::get('/seller/storage/recycle', [StorageController::class, 'recycle'])->name('dash.storage.recycle'),
    ];

    $sellerPaths = DashboardMenu::sellerPaths();

    foreach (DashboardMenu::pages() as $path => $leaf) {
        $route = isset($implemented[$path])
            ? $implemented[$path]()
            : Route::get($path, [DashboardController::class, 'placeholder'])->name('dash.'.$leaf['key']);

        if (in_array($path, $sellerPaths, true)) {
            $route->middleware('seller');
        }

        // Every Storage page asks for the Privacy Password (after the seller check).
        if (str_starts_with($path, '/seller/storage')) {
            $route->middleware('privacy');
        }
    }

    // Seller onboarding (spec 3.2 / 6.3)
    Route::get(DashboardMenu::ONBOARDING_PATH, [OnboardingController::class, 'show'])->name('seller.onboarding');
    Route::post('/seller/activate', [OnboardingController::class, 'activate'])->middleware('throttle:10,1')->name('seller.activate');

    // Seller Center writes
    Route::middleware('seller')->group(function (): void {
        Route::put('/seller/store', [StoreSettingsController::class, 'update'])->name('seller.store.update');
        Route::put('/seller/store/featured', [StoreSettingsController::class, 'featured'])->name('seller.store.featured');
        Route::post('/seller/services', [ServiceApplicationController::class, 'store'])->middleware('throttle:30,1')->name('seller.services.store');
        Route::post('/seller/info', [SellerInfoController::class, 'update'])->middleware('throttle:20,1')->name('seller.info.update');

        // Store coupons (spec 1.8 "My Coupon Codes")
        // Sell Order Comments (spec 1.8): one reply per comment
        Route::post('/seller/comments/{comment}/reply', [SellerCommentController::class, 'reply'])->whereNumber('comment')->middleware('throttle:30,1')->name('seller.comments.reply');

        Route::post('/seller/coupons', [SellerCouponController::class, 'store'])->middleware('throttle:30,1')->name('seller.coupons.store');
        Route::post('/seller/coupons/end', [SellerCouponController::class, 'end'])->name('seller.coupons.end');
        Route::post('/seller/coupons/{publicId}/extend', [SellerCouponController::class, 'extend'])->where('publicId', '[0-9a-z]{26}')->name('seller.coupons.extend');

        // Marketing campaigns and paid marketing e-mails (spec 14.2 / 14.3)
        Route::post('/seller/campaigns/{campaign}/apply', [CampaignController::class, 'apply'])->whereNumber('campaign')->middleware('throttle:20,1')->name('seller.campaigns.apply');
        Route::post('/seller/campaigns/{campaign}/withdraw', [CampaignController::class, 'withdraw'])->whereNumber('campaign')->name('seller.campaigns.withdraw');
        Route::post('/seller/marketing/emails', [MarketingEmailController::class, 'store'])->middleware('throttle:6,1')->name('seller.marketing.send');

        // Offers (spec 1.8)
        Route::post('/seller/offers', [OfferController::class, 'store'])->middleware('throttle:30,1')->name('seller.offers.store');
        Route::get('/seller/offers/{offer}/edit', [OfferController::class, 'edit'])->whereNumber('offer')->name('seller.offers.edit');
        Route::put('/seller/offers/{offer}', [OfferController::class, 'update'])->whereNumber('offer')->middleware('throttle:60,1')->name('seller.offers.update');
        Route::post('/seller/offers/bulk', [OfferController::class, 'bulk'])->middleware('throttle:30,1')->name('seller.offers.bulk');
        Route::patch('/seller/offers/{offer}/inline', [OfferController::class, 'inline'])->whereNumber('offer')->middleware('throttle:120,1')->name('seller.offers.inline');
        // Sold Orders (spec 1.8 / 6.1 / 8.2)
        Route::prefix('/seller/orders/{publicId}')->where(['publicId' => '[0-9a-z]{26}'])->group(function (): void {
            Route::get('/', [SoldOrderController::class, 'show'])->name('seller.orders.show');
            Route::post('/substatus', [SoldOrderController::class, 'substatus'])->middleware('throttle:60,1')->name('seller.orders.substatus');
            Route::post('/deliver', [SoldOrderController::class, 'deliver'])->middleware('throttle:30,1')->name('seller.orders.deliver');
            Route::post('/milestones', [SoldOrderController::class, 'milestone'])->middleware('throttle:30,1')->name('seller.orders.milestones');
            Route::put('/notes', [SoldOrderController::class, 'notes'])->middleware('throttle:60,1')->name('seller.orders.notes');
            Route::post('/cancel', [SoldOrderController::class, 'cancel'])->middleware('throttle:10,1')->name('seller.orders.cancel');
            Route::get('/evidence/{evidence}', [SoldOrderController::class, 'evidence'])->whereNumber('evidence')->name('seller.orders.evidence');
        });
        Route::post('/seller/stock-alerts/dismiss', [StockAlertController::class, 'dismiss'])->name('seller.stock-alerts.dismiss');
        // Withdrawal Management (spec 1.8 / 6.6): requesting needs a recently confirmed password.
        Route::post('/seller/withdrawals', [WithdrawalController::class, 'store'])->middleware(['recent.password', 'throttle:10,1'])->name('seller.withdrawals.store');
        Route::post('/seller/withdrawals/{publicId}/cancel', [WithdrawalController::class, 'cancel'])->where('publicId', '[0-9a-z]{26}')->name('seller.withdrawals.cancel');
        Route::post('/seller/withdrawals/methods', [WithdrawalController::class, 'storeMethod'])->middleware(['recent.password', 'throttle:10,1'])->name('seller.withdrawals.methods.store');
        Route::post('/seller/withdrawals/methods/{method}/default', [WithdrawalController::class, 'defaultMethod'])->whereNumber('method')->name('seller.withdrawals.methods.default');
        Route::delete('/seller/withdrawals/methods/{method}', [WithdrawalController::class, 'removeMethod'])->whereNumber('method')->name('seller.withdrawals.methods.remove');
        // Violation Center (spec 17.2)
        Route::post('/seller/violations/{publicId}/appeal', [ViolationController::class, 'appeal'])->where('publicId', '[0-9a-z]{26}')->middleware('throttle:10,1')->name('seller.violations.appeal');

        // Storage writes (spec 8.1), behind the Privacy Password like the pages.
        Route::middleware('privacy')->group(function (): void {
            Route::post('/seller/storage/groups', [StorageController::class, 'storeGroup'])->middleware('throttle:20,1')->name('seller.storage.groups.store');
            Route::put('/seller/storage/groups/{group}', [StorageController::class, 'updateGroup'])->whereNumber('group')->name('seller.storage.groups.update');
            Route::post('/seller/storage/groups/{group}/import', [StorageController::class, 'import'])->whereNumber('group')->middleware('throttle:20,1')->name('seller.storage.import');
            Route::post('/seller/storage/items/{item}/reveal', [StorageController::class, 'reveal'])->whereNumber('item')->middleware('throttle:30,1')->name('seller.storage.reveal');
            Route::post('/seller/storage/items/manage', [StorageController::class, 'manage'])->middleware('throttle:30,1')->name('seller.storage.manage');
        });
    });

    // Checkout (spec 1.6, 9, Part 20): verified e-mail required.
    Route::middleware('verified')->group(function (): void {
        Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
        Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:20,1')->name('checkout.store');
    });
    // Purchase Orders (spec 6.1 / 8.3)
    // Purchase Order Comment (spec Part 13)
    Route::post('/buy/orders/{publicId}/comment', [CommentController::class, 'store'])->where('publicId', '[0-9a-z]{26}')->middleware('throttle:20,1')->name('buy.comments.store');
    Route::put('/buy/comments/{comment}', [CommentController::class, 'update'])->whereNumber('comment')->middleware('throttle:20,1')->name('buy.comments.update');

    Route::prefix('/buy/orders/{publicId}')->where(['publicId' => '[0-9a-z]{26}'])->group(function (): void {
        Route::get('/', [PurchaseOrderController::class, 'show'])->name('buy.orders.show');
        Route::post('/confirm', [PurchaseOrderController::class, 'confirm'])->middleware('throttle:20,1')->name('buy.orders.confirm');
        Route::post('/reveal', [PurchaseOrderController::class, 'reveal'])->middleware(['recent.password', 'throttle:30,1'])->name('buy.orders.reveal');
        Route::post('/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('throttle:10,1')->name('buy.orders.cancel');
        Route::post('/cancel-remainder', [PurchaseOrderController::class, 'cancelRemainder'])->middleware('throttle:10,1')->name('buy.orders.cancel-remainder');
        Route::get('/evidence/{evidence}', [PurchaseOrderController::class, 'evidence'])->whereNumber('evidence')->name('buy.orders.evidence');
    });

    Route::get('/buy/funding/export', [FinanceController::class, 'fundingCsv'])->middleware('throttle:20,1')->name('buy.funding.export');

    // Announcement centre (spec 12.2)
    Route::post('/announcements/read-all', [AnnouncementController::class, 'readAll'])->name('announcements.read-all');
    Route::post('/announcements/{id}/read', [AnnouncementController::class, 'read'])->where('id', '[na][0-9]+')->name('announcements.read');
    Route::post('/announcements/{id}/star', [AnnouncementController::class, 'star'])->where('id', '[na][0-9]+')->name('announcements.star');

    // Invoices (Part 16)
    Route::put('/invoices/info', [InvoiceController::class, 'saveInfo'])->name('invoices.info.save');
    Route::get('/invoices/view/{number}', [InvoiceController::class, 'show'])->where('number', '[A-Z0-9-]{8,24}')->name('invoices.show');

    // Service tickets (spec Part 11 / Part 20)
    Route::get('/tickets/new', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('throttle:10,1')->name('tickets.store');
    Route::get('/tickets/{ticket}/files/{attachment}', [TicketController::class, 'file'])->whereNumber(['ticket', 'attachment'])->name('tickets.file');
    Route::prefix('/tickets/{publicId}')->where(['publicId' => '[0-9a-z]{26}'])->group(function (): void {
        Route::get('/', [TicketController::class, 'show'])->name('tickets.show');
        Route::post('/messages', [TicketController::class, 'reply'])->middleware('throttle:30,1')->name('tickets.reply');
        Route::post('/close', [TicketController::class, 'close'])->name('tickets.close');
        Route::post('/reopen', [TicketController::class, 'reopen'])->name('tickets.reopen');
        Route::post('/rate', [TicketController::class, 'rate'])->name('tickets.rate');
    });

    // Dispute Center (spec Part 10 / Part 20): open from an order, thread, proposals, escalation
    Route::get('/orders/{publicId}/disputes/new', [DisputeController::class, 'create'])->where('publicId', '[0-9a-z]{26}')->name('disputes.create');
    Route::post('/orders/{publicId}/disputes', [DisputeController::class, 'store'])->where('publicId', '[0-9a-z]{26}')->middleware('throttle:10,1')->name('disputes.store');
    Route::prefix('/disputes/{publicId}')->where(['publicId' => '[0-9a-z]{26}'])->group(function (): void {
        Route::get('/', [DisputeController::class, 'show'])->name('disputes.show');
        Route::post('/messages', [DisputeController::class, 'reply'])->middleware('throttle:30,1')->name('disputes.reply');
        Route::post('/propose', [DisputeController::class, 'propose'])->middleware('throttle:20,1')->name('disputes.propose');
        Route::post('/accept', [DisputeController::class, 'accept'])->middleware('throttle:10,1')->name('disputes.accept');
        Route::post('/escalate', [DisputeController::class, 'escalate'])->middleware('throttle:10,1')->name('disputes.escalate');
        Route::post('/withdraw', [DisputeController::class, 'withdraw'])->middleware('throttle:10,1')->name('disputes.withdraw');
        Route::get('/evidence/{evidence}', [DisputeController::class, 'evidence'])->whereNumber('evidence')->name('disputes.evidence');
    });

    // Chat (spec 12.1): conversation list/thread, pre-sale "Contact seller", order chat, polling
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{publicId}', [ChatController::class, 'index'])->where('publicId', '[0-9a-z]{26}')->name('chat.show');
    Route::get('/chat/{publicId}/poll', [ChatController::class, 'poll'])->where('publicId', '[0-9a-z]{26}')->middleware('throttle:120,1')->name('chat.poll');
    Route::post('/chat/{publicId}/messages', [ChatController::class, 'send'])->where('publicId', '[0-9a-z]{26}')->middleware('throttle:30,1')->name('chat.send');
    Route::post('/chat/{publicId}/mute', [ChatController::class, 'mute'])->where('publicId', '[0-9a-z]{26}')->name('chat.mute');
    Route::post('/chat/store/{slug}', [ChatController::class, 'startPreSale'])->middleware('throttle:20,1')->name('chat.start');
    Route::post('/chat/order/{publicId}', [ChatController::class, 'forOrder'])->where('publicId', '[0-9a-z]{26}')->name('chat.order');

    // Affiliate Program (spec Part 15)
    Route::post('/affiliate/enrol', [AffiliateController::class, 'enrol'])->middleware('throttle:10,1')->name('affiliate.enrol');
    Route::post('/affiliate/code', [AffiliateController::class, 'code'])->middleware('throttle:10,1')->name('affiliate.code');
    Route::post('/affiliate/links', [AffiliateController::class, 'link'])->middleware('throttle:30,1')->name('affiliate.links');
    Route::post('/affiliate/transfer', [AffiliateController::class, 'transfer'])->middleware(['recent.password', 'throttle:10,1'])->name('affiliate.transfer');
    Route::post('/affiliate/videos', [AffiliateController::class, 'submitVideo'])->middleware('throttle:20,1')->name('affiliate.videos.submit');

    // Delete Account (spec 6.8): two factors, cool-off, cancel
    Route::post('/settings/delete-account/code', [DeleteAccountController::class, 'code'])->middleware('throttle:5,10')->name('settings.delete.code');
    Route::post('/settings/delete-account', [DeleteAccountController::class, 'store'])->middleware('throttle:10,10')->name('settings.delete.store');
    Route::post('/settings/delete-account/cancel', [DeleteAccountController::class, 'cancel'])->name('settings.delete.cancel');

    // Coupon Center (spec 14.1): claim, buy with points, store code
    Route::post('/coupons/code', [CouponController::class, 'code'])->middleware('throttle:20,1')->name('coupons.code');
    Route::post('/coupons/{publicId}/claim', [CouponController::class, 'claim'])->where('publicId', '[0-9a-z]{26}')->middleware('throttle:30,1')->name('coupons.claim');
    Route::post('/coupons/{publicId}/redeem', [CouponController::class, 'redeem'])->where('publicId', '[0-9a-z]{26}')->middleware('throttle:10,1')->name('coupons.redeem');

    // Favorites (spec Part 20: POST /api/favorites toggle)
    Route::post('/favorites', [EngagementController::class, 'toggleFavorite'])->middleware('throttle:60,1')->name('favorites.toggle');

    // Settings › Account
    Route::put('/settings/account', [AccountController::class, 'update'])->name('settings.account.update');
    // Personal data download (spec Part 23, GDPR): recent password, a few times per hour
    Route::get('/settings/account/export', [OpsController::class, 'export'])->middleware(['recent.password', 'throttle:data-export'])->name('settings.account.export');

    // Settings › Security
    Route::put('/settings/security/email', [SecurityController::class, 'changeEmail'])->name('settings.security.email');
    Route::post('/settings/security/phone/code', [SecurityController::class, 'sendPhoneCode'])->middleware('throttle:6,1')->name('settings.security.phone.code');
    Route::post('/settings/security/phone/confirm', [SecurityController::class, 'confirmPhone'])->middleware('throttle:10,1')->name('settings.security.phone.confirm');
    Route::put('/settings/security/privacy-password', [SecurityController::class, 'setPrivacyPassword'])->name('settings.security.privacy');
    Route::delete('/settings/security/sessions/{session}', [SecurityController::class, 'revokeSession'])->name('settings.security.sessions.revoke');
    Route::post('/settings/security/sessions/revoke-others', [SecurityController::class, 'revokeOtherSessions'])->name('settings.security.sessions.revoke-others');

    // Privacy Password gate (used by Storage in M5)
    Route::get('/security/privacy-verify', [SecurityController::class, 'showPrivacyVerify'])->name('privacy.verify');
    Route::post('/security/privacy-verify', [SecurityController::class, 'privacyVerify'])->middleware('throttle:5,1')->name('privacy.verify.store');

    // Settings › Certification
    Route::post('/settings/certification/identity', [CertificationController::class, 'startIdentity'])->middleware('throttle:5,60')->name('settings.certification.identity');
});
