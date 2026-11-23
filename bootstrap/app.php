<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Locale & display currency are plain preferences, readable without decryption.
        $middleware->encryptCookies(except: [ResolveLocale::LOCALE_COOKIE, ResolveLocale::CURRENCY_COOKIE]);

        // `auth` runs before ResolveLocale (middleware priority), so resolve the locale here.
        $middleware->redirectGuestsTo(fn (Request $request): string => '/'.ResolveLocale::preferredLocale($request).'/login');

        $middleware->web(append: [
            ResolveLocale::class,
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
