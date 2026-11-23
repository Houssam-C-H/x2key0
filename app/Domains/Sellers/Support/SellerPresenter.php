<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Support;

use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerServiceApplication;
use App\Http\Middleware\ResolveLocale;
use Locale;

/**
 * Props shared by the Seller Center screens and the public store page.
 */
final class SellerPresenter
{
    /** Languages a store can list as spoken (ISO 639-1). */
    public const array LANGUAGES = ['en', 'fr', 'ar', 'es', 'de', 'pt', 'it', 'ru', 'tr', 'zh', 'ja', 'ko', 'hi', 'id', 'vi', 'th', 'pl', 'nl'];

    /**
     * @return array{code: string, name: string, stars: int, commissionBps: int, holdDays: int, reserveBps: int, maxActiveOffers: int, autoApprove: bool}|null
     */
    public static function level(?SellerLevel $level, string $locale): ?array
    {
        if ($level === null) {
            return null;
        }

        return [
            'code' => $level->code,
            'name' => $level->translate('name', $locale),
            'stars' => $level->stars,
            'commissionBps' => $level->commission_bps,
            'holdDays' => $level->hold_days,
            'reserveBps' => $level->reserve_bps,
            'maxActiveOffers' => $level->max_active_offers,
            'autoApprove' => $level->auto_approve,
        ];
    }

    /**
     * @return list<array{code: string, name: string, stars: int, commissionBps: int, holdDays: int, reserveBps: int, maxActiveOffers: int, autoApprove: bool}>
     */
    public static function levels(string $locale): array
    {
        $out = [];

        foreach (SellerLevel::query()->orderBy('sort')->get() as $level) {
            $presented = self::level($level, $locale);
            if ($presented !== null) {
                $out[] = $presented;
            }
        }

        return $out;
    }

    /**
     * Game×categories the store is approved for, visible ones only.
     *
     * @return list<array{id: int, title: string, href: string}>
     */
    public static function passedServices(SellerProfile $profile, string $locale): array
    {
        $services = $profile->serviceApplications()
            ->where('status', ServiceApplicationStatus::Passed)
            ->whereHas('gameCategory', fn ($q) => $q->visible())
            ->with(['gameCategory.game', 'gameCategory.type'])
            ->get()
            ->map(fn (SellerServiceApplication $a): array => self::service($a->gameCategory, $locale))
            ->sortBy('title')
            ->values()
            ->all();

        return array_values($services);
    }

    /**
     * @return array{id: int, title: string, href: string}
     */
    public static function service(GameCategory $gameCategory, string $locale): array
    {
        return ['id' => $gameCategory->id, 'title' => $gameCategory->title($locale), 'href' => $gameCategory->path($locale)];
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function languageOptions(string $locale): array
    {
        return array_map(static fn (string $code): array => ['code' => $code, 'name' => self::languageName($code, $locale)], self::LANGUAGES);
    }

    public static function languageName(string $code, string $locale): string
    {
        $name = Locale::getDisplayLanguage($code, $locale);

        return $name === false || $name === $code ? strtoupper($code) : mb_convert_case($name, MB_CASE_TITLE);
    }

    /**
     * @param  array<array-key, mixed>|null  $values
     * @return array<string, string|null> one entry per supported locale
     */
    public static function perLocale(?array $values): array
    {
        $out = [];

        foreach (ResolveLocale::supportedLocales() as $code) {
            $value = $values[$code] ?? null;
            $out[$code] = is_string($value) ? $value : null;
        }

        return $out;
    }
}
