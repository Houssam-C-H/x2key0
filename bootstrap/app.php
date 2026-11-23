<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureSeller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePrivacyPassword;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\RequireStaffTwoFactor;
use App\Http\Middleware\ResolveLocale;
use App\Http\Middleware\TrackUserSession;
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

        $middleware->redirectUsersTo('/dashboard');

        $middleware->web(append: [
            ResolveLocale::class,
            TrackUserSession::class,
            HandleInertiaRequests::class,
        ]);

        // PSP webhooks authenticate with their signature, not a CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        $middleware->alias([
            'privacy' => RequirePrivacyPassword::class,
            'staff.2fa' => RequireStaffTwoFactor::class,
            'seller' => EnsureSeller::class,
            'recent.password' => RequireRecentPassword::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Storage imports and account forms carry secrets: never flash them back into the session.
        $exceptions->dontFlash(['items', 'account', 'payload', 'buyer_inputs', 'privacy_password', 'current_password', 'password', 'password_confirmation']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
