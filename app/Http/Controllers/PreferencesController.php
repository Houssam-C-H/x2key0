<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/**
 * Saves the locale/currency modal choice (spec 1.1) in cookies and returns the user
 * to the same page in the new locale.
 */
final class PreferencesController
{
    private const int ONE_YEAR_MINUTES = 60 * 24 * 365;

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var array{locale: string, currency: string, redirect?: string|null} $data */
        $data = $request->validate([
            'locale' => ['required', Rule::in(ResolveLocale::supportedLocales())],
            'currency' => ['required', Rule::in((array) config('x2key.display_currencies'))],
            'redirect' => ['nullable', 'string', 'max:2000'],
        ]);

        $target = self::localizedPath($data['redirect'] ?? '/', $data['locale']);

        return redirect($target)
            ->withCookie(Cookie::make(ResolveLocale::LOCALE_COOKIE, $data['locale'], self::ONE_YEAR_MINUTES))
            ->withCookie(Cookie::make(ResolveLocale::CURRENCY_COOKIE, $data['currency'], self::ONE_YEAR_MINUTES));
    }

    /**
     * Swaps the locale segment of a same-site path. Only relative paths are accepted
     * (no open redirect); non-localized paths (dashboard) are returned unchanged.
     */
    public static function localizedPath(string $path, string $locale): string
    {
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/'.$locale;
        }

        $segments = explode('/', ltrim($path, '/'), 2);

        if (in_array($segments[0], ResolveLocale::supportedLocales(), true)) {
            return '/'.$locale.(isset($segments[1]) && $segments[1] !== '' ? '/'.$segments[1] : '');
        }

        return $path === '/' ? '/'.$locale : $path;
    }
}
