<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('x2key.brand.name'),
            ],
            'locale' => $locale,
            'dir' => ResolveLocale::direction($locale),
            'locales' => ResolveLocale::localeOptions(),
            'currency' => $request->attributes->get('display_currency'),
            'currencies' => config('x2key.display_currencies'),
            'translations' => fn (): array => self::translations($locale),
            'categoryTypes' => array_map(
                static fn (CategoryTypeCode $c): array => ['code' => $c->value, 'label' => __($c->labelKey()), 'hero' => $c->inHero()],
                CategoryTypeCode::cases(),
            ),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->getAuthIdentifier(),
                    'name' => $user->getAttribute('name'),
                    'email' => $user->getAttribute('email'),
                ],
            ],
            'flash' => [
                'success' => fn (): mixed => $request->session()->get('success'),
                'error' => fn (): mixed => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * UI strings for the current locale, falling back to English per key.
     *
     * @return array<string, string>
     */
    public static function translations(string $locale): array
    {
        $load = static function (string $code): array {
            $path = lang_path("{$code}.json");

            if (! File::exists($path)) {
                return [];
            }

            /** @var array<string, string> */
            return json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        };

        return array_replace($load('en'), $locale === 'en' ? [] : $load($locale));
    }
}
