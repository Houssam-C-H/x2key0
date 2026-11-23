<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale resolution order:
 *   1. `{locale}` route parameter (public, SSR, indexable pages)
 *   2. `locale` cookie (set by the locale/currency modal)
 *   3. Accept-Language header
 *   4. config('x2key.default_locale')
 *
 * Also resolves the display currency from the `currency` cookie (D5: display only).
 */
final class ResolveLocale
{
    public const string LOCALE_COOKIE = 'locale';

    public const string CURRENCY_COOKIE = 'currency';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = self::supportedLocales();
        $fromRoute = $request->route('locale');

        $locale = match (true) {
            is_string($fromRoute) && in_array($fromRoute, $supported, true) => $fromRoute,
            default => self::preferredLocale($request),
        };

        app()->setLocale($locale);
        URL::defaults(['locale' => $locale]);

        $request->attributes->set('display_currency', self::displayCurrency($request));

        $response = $next($request);

        // Remember an explicit locale choice made through the URL.
        if (is_string($fromRoute) && $request->cookie(self::LOCALE_COOKIE) !== $locale) {
            $response->headers->setCookie(Cookie::make(self::LOCALE_COOKIE, $locale, 60 * 24 * 365));
        }

        return $response;
    }

    /**
     * @return list<string>
     */
    public static function supportedLocales(): array
    {
        /** @var array<string, mixed> $locales */
        $locales = config('x2key.locales');

        return array_keys($locales);
    }

    public static function preferredLocale(Request $request): string
    {
        $supported = self::supportedLocales();
        $cookie = $request->cookie(self::LOCALE_COOKIE);

        if (is_string($cookie) && in_array($cookie, $supported, true)) {
            return $cookie;
        }

        $browser = $request->getPreferredLanguage($supported);

        if ($browser !== null && in_array($browser, $supported, true)) {
            return $browser;
        }

        /** @var string */
        return config('x2key.default_locale');
    }

    public static function displayCurrency(Request $request): string
    {
        /** @var list<string> $currencies */
        $currencies = config('x2key.display_currencies');
        $cookie = $request->cookie(self::CURRENCY_COOKIE);

        if (is_string($cookie) && in_array($cookie, $currencies, true)) {
            return $cookie;
        }

        /** @var string */
        return config('x2key.settlement_currency');
    }

    public static function direction(string $locale): string
    {
        return config()->string("x2key.locales.{$locale}.dir", 'ltr');
    }

    /**
     * @return list<array{code: string, name: string, dir: string}>
     */
    public static function localeOptions(): array
    {
        $options = [];

        foreach (config()->array('x2key.locales') as $code => $locale) {
            if (is_string($code) && is_array($locale) && is_string($locale['name'] ?? null) && is_string($locale['dir'] ?? null)) {
                $options[] = ['code' => $code, 'name' => $locale['name'], 'dir' => $locale['dir']];
            }
        }

        return $options;
    }
}
