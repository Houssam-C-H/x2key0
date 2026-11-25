<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Announcements\Support\AnnouncementCenter;
use App\Domains\Catalog\Enums\CategoryTypeCode;
use App\Domains\Catalog\Support\CatalogReader;
use App\Domains\Content\Support\FooterContent;
use App\Domains\Identity\Models\User;
use App\Domains\Messaging\Support\ChatPresenter;
use App\Domains\Storage\Support\StockAlerts;
use App\Http\Controllers\BookmarkPromptController;
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

        if ($user instanceof User) {
            $user->unsetRelation('sellerProfile'); // store status may have changed since the user was loaded
        }

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
                    'seller' => $user->sellerProfile === null ? null : [
                        'slug' => $user->sellerProfile->slug,
                        'status' => $user->sellerProfile->status->value,
                        'opensSellerCenter' => $user->sellerProfile->status->opensSellerCenter(),
                    ],
                ] : null,
            ],
            // Mega menu columns (spec 1.1), cached per locale and flushed on catalog edits.
            'megaMenu' => fn (): array => app(CatalogReader::class)->megaMenu($locale),
            // Footer pages and SEO keyword row (spec 1.3 #12), and the bookmark prompt (spec 1.1), on public pages.
            'footer' => fn (): ?array => $request->route('locale') === null ? null : FooterContent::data($locale),
            'bookmarkPrompt' => fn (): bool => $request->route('locale') !== null && BookmarkPromptController::show($request),
            // Dashboard sidebar for signed-in, non-localized (dashboard) pages; evaluated lazily.
            'menu' => fn (): ?array => $user instanceof User && $request->route('locale') === null
                ? app(DashboardMenuPresenter::class)->present($request)
                : null,
            // Announcement bell (spec 12.2): unread notifications + announcements.
            'inbox' => fn (): ?array => $user instanceof User ? ['unread' => app(AnnouncementCenter::class)->unreadCount($user)] : null,
            // Chat badge (spec 12.1): conversations with unread messages.
            'chat' => fn (): ?array => $user instanceof User ? ['unread' => app(ChatPresenter::class)->unreadConversations($user)] : null,
            // Seller stock bar (spec 1.1 / 8.1): open, undismissed low-stock alerts, on dashboard pages.
            'stockAlert' => fn (): ?array => $user instanceof User && $request->route('locale') === null && $user->sellerProfile?->status->opensSellerCenter() === true
                ? StockAlerts::bar($user->sellerProfile->id)
                : null,
            // Storage switched off by staff (ADR-034): banner on the seller's Storage pages.
            'storageDisabled' => fn (): bool => $user instanceof User && $request->route('locale') === null && $user->sellerProfile !== null && ! $user->sellerProfile->storage_enabled,
            'flash' => [
                'success' => fn (): mixed => $request->session()->get('success'),
                'error' => fn (): mixed => $request->session()->get('error'),
                // Row errors of the last offer CSV import (Seller Center › Active offers).
                'csvErrors' => fn (): mixed => $request->session()->get('csvErrors'),
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
