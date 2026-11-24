<?php

declare(strict_types=1);

namespace App\Domains\Content\Support;

use App\Domains\Content\Models\Page;
use App\Domains\Content\Models\SeoLinkRow;

/** Footer (spec 1.3 #12): CMS pages per column and the SEO keyword row, cached per locale. */
final class FooterContent
{
    /**
     * @return array{columns: array<string, list<array{label: string, href: string}>>, seoLinks: list<array{label: string, href: string}>}
     */
    public static function data(string $locale): array
    {
        /** @var array{columns: array<string, list<array{label: string, href: string}>>, seoLinks: list<array{label: string, href: string}>} */
        return HomeCache::remember("footer.{$locale}", static function () use ($locale): array {
            $columns = array_fill_keys(Page::FOOTER_COLUMNS, []);
            foreach (Page::query()->where('is_published', true)->whereNotNull('footer_column')->orderBy('sort')->orderBy('id')->get() as $page) {
                if (isset($columns[$page->footer_column])) {
                    $columns[$page->footer_column][] = ['label' => $page->translate('title', $locale), 'href' => $page->path($locale)];
                }
            }

            $seo = array_values(SeoLinkRow::query()->where('is_enabled', true)->orderBy('sort')->limit(60)->get()
                ->map(static fn (SeoLinkRow $r): array => ['label' => $r->translate('label', $locale), 'href' => $r->url])->all());

            return ['columns' => $columns, 'seoLinks' => $seo];
        });
    }
}
