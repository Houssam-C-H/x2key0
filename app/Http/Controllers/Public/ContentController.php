<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domains\Content\Models\HelpArticle;
use App\Domains\Content\Models\NewsArticle;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Support\ContentRenderer;
use App\Domains\Content\Support\HomePresenter;
use App\Http\Support\Seo;
use App\Shared\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** News, Help center and CMS pages (spec 1.2 / Part 20: `/news`, `/news/{slug}`, `/help`, `/help/{slug}`, `/page/{slug}`). */
final class ContentController
{
    private const int NEWS_PER_PAGE = 12;

    public function news(Request $request, string $locale): Response
    {
        $page = NewsArticle::query()->published()->orderByDesc('published_at')->paginate(self::NEWS_PER_PAGE)->withQueryString();

        return Inertia::render('Public/Content/NewsIndex', [
            'articles' => array_values(collect($page->items())->map(static fn (NewsArticle $a): array => HomePresenter::article($a, $locale))->all()),
            'pagination' => ['page' => $page->currentPage(), 'last' => $page->lastPage()],
            'seo' => Seo::make((string) __('content.news.title'), (string) __('content.news.description'), '/news', $locale),
        ]);
    }

    public function article(string $locale, string $slug): Response
    {
        $article = NewsArticle::query()->published()->where('slug', $slug)->with('game')->firstOrFail();
        $title = $article->translate('title', $locale);

        return Inertia::render('Public/Content/Article', [
            'kind' => 'news',
            'article' => [
                ...HomePresenter::article($article, $locale),
                'html' => ContentRenderer::html($article->translate('body', $locale)),
                'game' => $article->game === null ? null : ['name' => $article->game->translate('name', $locale), 'href' => "/{$locale}/{$article->game->slug}"],
            ],
            'related' => array_values(NewsArticle::query()->published()->whereKeyNot($article->id)->orderByDesc('published_at')->limit(3)->get()
                ->map(static fn (NewsArticle $a): array => HomePresenter::article($a, $locale))->all()),
            'seo' => Seo::make($title, $article->excerpt === null ? $title : $article->translate('excerpt', $locale), "/news/{$article->slug}", $locale),
        ]);
    }

    /** Buyer protection (designed page): how money is held, what is covered, how to claim. */
    public function buyerProtection(Settings $settings, string $locale): Response
    {
        return Inertia::render('Public/BuyerProtection', [
            'confirmationHours' => $settings->int('confirmation_window_hours'),
            'serviceConfirmationHours' => $settings->int('confirmation_window_hours_services'),
            'seo' => Seo::make((string) __('protection.title'), (string) __('protection.description'), '/buyer-protection', $locale),
        ]);
    }

    public function help(string $locale): Response
    {
        $sections = [];
        foreach (HelpArticle::query()->where('is_published', true)->orderBy('sort')->orderBy('id')->get() as $a) {
            $sections[$a->section][] = ['title' => $a->translate('title', $locale), 'href' => $a->path($locale)];
        }

        return Inertia::render('Public/Content/HelpIndex', [
            'sections' => self::sections($sections),
            'seo' => Seo::make((string) __('content.help.title'), (string) __('content.help.description'), '/help', $locale),
        ]);
    }

    /**
     * Sections in their fixed order, empty ones left out.
     *
     * @param  array<string, list<array{title: string, href: string}>>  $articles
     * @return list<array{code: string, articles: list<array{title: string, href: string}>}>
     */
    private static function sections(array $articles): array
    {
        $out = [];
        foreach (HelpArticle::SECTIONS as $code) {
            if (isset($articles[$code])) {
                $out[] = ['code' => $code, 'articles' => $articles[$code]];
            }
        }

        return $out;
    }

    public function helpArticle(string $locale, string $slug): Response
    {
        $article = HelpArticle::query()->where('is_published', true)->where('slug', $slug)->firstOrFail();
        $title = $article->translate('title', $locale);
        $siblings = HelpArticle::query()->where('is_published', true)->where('section', $article->section)->orderBy('sort')->get();

        return Inertia::render('Public/Content/Article', [
            'kind' => 'help',
            'article' => ['title' => $title, 'section' => $article->section, 'html' => ContentRenderer::html($article->translate('body', $locale))],
            'related' => array_values($siblings->map(static fn (HelpArticle $a): array => ['title' => $a->translate('title', $locale), 'href' => $a->path($locale), 'current' => $a->id === $article->id])->all()),
            'seo' => Seo::make($title, ContentRenderer::summary($article->translate('body', $locale)), "/help/{$article->slug}", $locale),
        ]);
    }

    public function page(string $locale, string $slug): Response
    {
        $page = Page::query()->where('is_published', true)->where('slug', $slug)->firstOrFail();
        $title = $page->translate('title', $locale);

        return Inertia::render('Public/Content/Article', [
            'kind' => 'page',
            'article' => ['title' => $title, 'html' => ContentRenderer::html($page->translate('body', $locale)), 'updatedAt' => $page->updated_at->toIso8601String()],
            'related' => [],
            'seo' => Seo::make($title, ContentRenderer::summary($page->translate('body', $locale)), "/page/{$page->slug}", $locale),
        ]);
    }
}
