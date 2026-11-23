<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\LoginController;
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
        Route::get('/login', LoginController::class)->name('login');
    });

/*
| Dashboard (not localized; locale from cookie). Every sidebar entry is registered
| from DashboardMenu and renders a placeholder until its milestone ships.
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    foreach (DashboardMenu::pages() as $path => $leaf) {
        Route::get($path, [DashboardController::class, 'placeholder'])->name('dash.'.$leaf['key']);
    }
});
