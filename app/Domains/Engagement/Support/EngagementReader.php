<?php

declare(strict_types=1);

namespace App\Domains\Engagement\Support;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Support\CatalogPresenter;
use App\Domains\Engagement\Models\BrowsingHistory;
use App\Domains\Engagement\Models\Favorite;
use App\Domains\Offers\Models\Offer;
use App\Domains\Offers\Support\OfferPresenter;

/**
 * Read models for favorites and browsing history. Items that are no longer public are skipped, not shown
 * as broken cards.
 */
final class EngagementReader
{
    /**
     * Browsing History strip (spec 1.3 #2 / 1.5).
     *
     * @return list<array<string, mixed>>
     */
    public function history(?int $userId, ?string $guestToken, string $locale, int $limit = 12): array
    {
        if ($userId === null && $guestToken === null) {
            return [];
        }

        $rows = BrowsingHistory::query()
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id')->where('guest_token', $guestToken))
            ->orderByDesc('viewed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $products = $this->products($rows->pluck('product_id')->filter()->all());
        $offers = $this->offers($rows->pluck('offer_id')->filter()->all());

        $out = [];
        foreach ($rows as $row) {
            if ($row->product_id !== null && isset($products[$row->product_id])) {
                $out[] = ['historyId' => $row->id, 'kind' => 'product', ...CatalogPresenter::productCard($products[$row->product_id], $locale)];
            } elseif ($row->offer_id !== null && isset($offers[$row->offer_id])) {
                $out[] = ['historyId' => $row->id, 'kind' => 'offer', ...OfferPresenter::card($offers[$row->offer_id], $locale)];
            }
        }

        return $out;
    }

    /**
     * My Favorites (spec 1.7: cards with title, game, category, price).
     *
     * @return list<array<string, mixed>>
     */
    public function favorites(int $userId, string $locale): array
    {
        $rows = Favorite::query()->where('user_id', $userId)->whereIn('favoritable_type', [Favorite::OFFER, Favorite::PRODUCT])->latest('id')->get();

        $products = $this->products($rows->where('favoritable_type', Favorite::PRODUCT)->pluck('favoritable_id')->all());
        $offers = $this->offers($rows->where('favoritable_type', Favorite::OFFER)->pluck('favoritable_id')->all());

        $out = [];
        foreach ($rows as $row) {
            $item = match ($row->favoritable_type) {
                Favorite::PRODUCT => isset($products[$row->favoritable_id]) ? ['kind' => 'product', ...CatalogPresenter::productCard($products[$row->favoritable_id], $locale), 'gameCategory' => $products[$row->favoritable_id]->gameCategory->title($locale)] : null,
                default => isset($offers[$row->favoritable_id]) ? ['kind' => 'offer', ...OfferPresenter::card($offers[$row->favoritable_id], $locale)] : null,
            };

            if ($item !== null) {
                $out[] = [...$item, 'favoriteType' => $row->favoritable_type, 'addedAt' => $row->created_at->toIso8601String()];
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    public function favoritedIds(?int $userId, string $type, array $ids): array
    {
        if ($userId === null || $ids === []) {
            return [];
        }

        $out = [];
        foreach (Favorite::query()->where('user_id', $userId)->where('favoritable_type', $type)->whereIn('favoritable_id', $ids)->get(['favoritable_id']) as $favorite) {
            $out[$favorite->favoritable_id] = true;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return array<int, Product>
     */
    private function products(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        $products = Product::query()
            ->active()
            ->whereIn('products.id', $ids)
            ->whereHas('gameCategory', fn ($q) => $q->visible())
            ->with(['gameCategory.game', 'gameCategory.type', 'platform', 'region'])
            ->get();
        foreach ($products as $product) {
            $out[$product->id] = $product;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return array<int, Offer>
     */
    private function offers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Offer::query()->live()->whereIn('offers.id', $ids)->with(OfferPresenter::publicRelations())->get() as $offer) {
            $out[$offer->id] = $offer;
        }

        return $out;
    }
}
