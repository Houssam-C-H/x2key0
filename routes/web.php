<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Game;
use App\Http\Controllers\Auth\AuthPageController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\EngagementController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\Public\CatalogController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\SitemapController;
use App\Http\Controllers\Public\StoreController;
use App\Http\Controllers\Seller\MerchantContactsController;
use App\Http\Controllers\Seller\OfferController;
use App\Http\Controllers\Seller\OnboardingController;
use App\Http\Controllers\Seller\SellerInfoController;
use App\Http\Controllers\Seller\ServiceApplicationController;
use App\Http\Controllers\Seller\StockAlertController;
use App\Http\Controllers\Seller\StorageController;
use App\Http\Controllers\Seller\StoreSettingsController;
use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\CertificationController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Middleware\ResolveLocale;
use App\Shared\Navigation\DashboardMenu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$locales = implode('|', ResolveLocale::supportedLocales());

// Bare domain → the visitor's preferred locale.
Route::get('/', fn (Request $request) => redirect('/'.ResolveLocale::preferredLocale($request)))
    ->name('root');

Route::post('/preferences', PreferencesController::class)->name('preferences');

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
        '/buy/favorites' => fn () => Route::get('/buy/favorites', [EngagementController::class, 'favorites'])->name('dash.buy.favorites'),
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

        // Offers (spec 1.8)
        Route::post('/seller/offers', [OfferController::class, 'store'])->middleware('throttle:30,1')->name('seller.offers.store');
        Route::get('/seller/offers/{offer}/edit', [OfferController::class, 'edit'])->whereNumber('offer')->name('seller.offers.edit');
        Route::put('/seller/offers/{offer}', [OfferController::class, 'update'])->whereNumber('offer')->middleware('throttle:60,1')->name('seller.offers.update');
        Route::post('/seller/offers/bulk', [OfferController::class, 'bulk'])->middleware('throttle:30,1')->name('seller.offers.bulk');
        Route::patch('/seller/offers/{offer}/inline', [OfferController::class, 'inline'])->whereNumber('offer')->middleware('throttle:120,1')->name('seller.offers.inline');
        Route::post('/seller/stock-alerts/dismiss', [StockAlertController::class, 'dismiss'])->name('seller.stock-alerts.dismiss');

        // Storage writes (spec 8.1), behind the Privacy Password like the pages.
        Route::middleware('privacy')->group(function (): void {
            Route::post('/seller/storage/groups', [StorageController::class, 'storeGroup'])->middleware('throttle:20,1')->name('seller.storage.groups.store');
            Route::put('/seller/storage/groups/{group}', [StorageController::class, 'updateGroup'])->whereNumber('group')->name('seller.storage.groups.update');
            Route::post('/seller/storage/groups/{group}/import', [StorageController::class, 'import'])->whereNumber('group')->middleware('throttle:20,1')->name('seller.storage.import');
            Route::post('/seller/storage/items/{item}/reveal', [StorageController::class, 'reveal'])->whereNumber('item')->middleware('throttle:30,1')->name('seller.storage.reveal');
            Route::post('/seller/storage/items/manage', [StorageController::class, 'manage'])->middleware('throttle:30,1')->name('seller.storage.manage');
        });
    });

    // Favorites (spec Part 20: POST /api/favorites toggle)
    Route::post('/favorites', [EngagementController::class, 'toggleFavorite'])->middleware('throttle:60,1')->name('favorites.toggle');

    // Settings › Account
    Route::put('/settings/account', [AccountController::class, 'update'])->name('settings.account.update');

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
