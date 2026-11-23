<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Identity\Models\User;
use App\Shared\Navigation\DashboardMenuPresenter;
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
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'publicId' => $user->public_id,
                    'username' => $user->username,
                    'name' => $user->name,
                    'email' => $user->email,
                    'emailVerified' => $user->hasVerifiedEmail(),
                    'isStaff' => $user->isStaff(),
                ] : null,
            ],
            // Dashboard sidebar for signed-in, non-localized (dashboard) pages; evaluated lazily.
            'menu' => fn (): ?array => $user instanceof User && $request->route('locale') === null
                ? app(DashboardMenuPresenter::class)->present($request)
                : null,
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
