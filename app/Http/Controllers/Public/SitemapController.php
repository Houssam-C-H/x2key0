<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domains\Catalog\Models\Game;
use App\Domains\Catalog\Models\GameCategory;
use App\Domains\Catalog\Models\Product;
use App\Domains\Sellers\Models\SellerProfile;
use App\Http\Middleware\ResolveLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use XMLWriter;

/**
 * Sitemaps per locale (spec Part 22). Thin pages are left out: game×category pages need at least one
 * listed product (switches to "≥ 1 active offer" in M5), games need at least one such page.
 */
final class SitemapController
{
    public function index(): Response
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach (ResolveLocale::supportedLocales() as $locale) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', url("/sitemap-{$locale}.xml"));
            $xml->endElement();
        }

        $xml->endElement();

        return $this->xml($xml->outputMemory());
    }

    public function locale(string $locale): Response
    {
        abort_unless(in_array($locale, ResolveLocale::supportedLocales(), true), 404);

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $add = static function (string $path, ?string $lastmod = null) use ($xml, $locale): void {
            $xml->startElement('url');
            $xml->writeElement('loc', url("/{$locale}{$path}"));
            if ($lastmod !== null) {
                $xml->writeElement('lastmod', $lastmod);
            }
            $xml->endElement();
        };

        $add('');
        $add('/games');

        $categories = GameCategory::query()
            ->visible()
            ->where('product_count_cached', '>', 0)
            ->with('game')
            ->get();

        foreach ($categories->pluck('game')->unique('id') as $game) {
            /** @var Game $game */
            $add("/{$game->slug}");
        }

        foreach ($categories as $gameCategory) {
            $add("/{$gameCategory->game->slug}/{$gameCategory->slug}", $gameCategory->updated_at?->toDateString());
        }

        Product::query()
            ->active()
            ->whereHas('gameCategory', fn (Builder $q) => $q->visible())
            ->with('gameCategory.game')
            ->chunkById(1000, function ($products) use ($add): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $add("/{$product->gameCategory->game->slug}/{$product->gameCategory->slug}/{$product->slug}", $product->updated_at?->toDateString());
                }
            });

        // Public stores (active or on vacation).
        SellerProfile::query()->public()->select(['id', 'slug', 'updated_at'])->chunkById(1000, function ($stores) use ($add): void {
            foreach ($stores as $store) {
                /** @var SellerProfile $store */
                $add("/store/{$store->slug}", $store->updated_at->toDateString());
            }
        });

        $xml->endElement();

        return $this->xml($xml->outputMemory());
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
