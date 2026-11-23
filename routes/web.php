<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthPageController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\Public\HomeController;
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
    });

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
    ];

    foreach (DashboardMenu::pages() as $path => $leaf) {
        if (isset($implemented[$path])) {
            $implemented[$path]();

            continue;
        }

        Route::get($path, [DashboardController::class, 'placeholder'])->name('dash.'.$leaf['key']);
    }

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
