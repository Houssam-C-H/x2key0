<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Http\Middleware\ResolveLocale;

/**
 * SEO metadata for public pages (spec Part 22): title, description, canonical, hreflang alternates,
 * robots and JSON-LD. `$path` is the locale-less path, e.g. "/fortnite/accounts".
 */
final class Seo
{
    /**
     * @param  list<array<string, mixed>>  $jsonLd
     * @return array{title: string, description: string, canonical: string, alternates: list<array{locale: string, href: string}>, noindex: bool, jsonLd: list<array<string, mixed>>}
     */
    public static function make(string $title, string $description, string $path, string $locale, bool $noindex = false, array $jsonLd = []): array
    {
        $path = $path === '/' ? '' : $path;
        $alternates = [];

        foreach (ResolveLocale::supportedLocales() as $code) {
            $alternates[] = ['locale' => $code, 'href' => url("/{$code}{$path}")];
        }

        $alternates[] = ['locale' => 'x-default', 'href' => url('/'.config()->string('x2key.default_locale').$path)];

        return [
            'title' => $title,
            'description' => mb_substr($description, 0, 160),
            'canonical' => url("/{$locale}{$path}"),
            'alternates' => $alternates,
            'noindex' => $noindex,
            'jsonLd' => $jsonLd,
        ];
    }

    /**
     * @param  list<array{name: string, href: string}>  $crumbs
     * @return array<string, mixed>
     */
    public static function breadcrumbList(array $crumbs): array
    {
        $items = [];

        foreach ($crumbs as $i => $crumb) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name'], 'item' => url($crumb['href'])];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
}
