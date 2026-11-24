<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Support;

use App\Domains\Reviews\Models\OrderComment;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Collection;

/** Comment rows for the buyer, seller, store and home pages (spec Part 13). */
final class ReviewPresenter
{
    public const int COMMUNITY_MIN_TEXT = 20;

    /**
     * @param  'buyer'|'seller'|'public'  $viewer
     * @return array<string, mixed>
     */
    public static function row(OrderComment $c, string $viewer, string $locale): array
    {
        return [
            'id' => $c->id,
            'rating' => $c->rating,
            'scores' => ['delivery' => $c->score_delivery, 'service' => $c->score_service, 'description' => $c->score_description],
            'body' => $c->body,
            'reply' => $c->seller_reply,
            'repliedAt' => $c->replied_at?->toIso8601String(),
            'createdAt' => $c->created_at->toIso8601String(),
            'edited' => $c->edited_at !== null,
            'hidden' => $c->status === 'hidden',
            'buyer' => $viewer === 'buyer' ? null : SellerRatings::maskBuyer($c->buyer),
            'order' => $viewer === 'public' ? null : $c->order->number,
            'orderId' => $viewer === 'buyer' ? $c->order->public_id : null,
            'item' => is_string($title = $c->order->item->snapshot['title'] ?? null) ? $title : '',
            'store' => $viewer === 'buyer' ? ['name' => $c->seller->store_name, 'href' => $c->seller->path($locale)] : null,
        ];
    }

    /**
     * Community ratings (spec Part 13, home module 7): featured comments first, then recent positive ones with at
     * least 20 characters of text, one per seller, with that seller's positive rate.
     *
     * @param  list<int>  $pinned  comment ids pinned on the home CMS
     * @return list<array<string, mixed>>
     */
    public static function community(int $limit, string $locale, array $pinned = []): array
    {
        $base = OrderComment::query()->visible()->where('rating', 'positive')->whereNotNull('body')
            ->whereHas('seller', fn ($q) => $q->public())
            ->with(['buyer:id,name', 'seller:id,store_name,slug', 'seller.stats']);

        // Pinned on the home CMS (in pin order), then featured, then the recent rule.
        /** @var Collection<int, OrderComment> $picked */
        $picked = $pinned === [] ? collect() : (clone $base)->whereIn('order_comments.id', $pinned)->get()
            ->sortBy(static fn (OrderComment $c): int => (int) array_search($c->id, $pinned, true))->values();
        $picked = $picked->concat((clone $base)->where('featured_in_community', true)->orderByDesc('created_at')->limit($limit)->get())->unique('id');
        if ($picked->count() < $limit) {
            $more = (clone $base)->where('created_at', '>=', now()->subDays(90))->orderByDesc('created_at')->limit(100)->get()
                ->filter(static fn (OrderComment $c): bool => mb_strlen((string) $c->body) >= self::COMMUNITY_MIN_TEXT);
            $picked = $picked->concat($more)->unique('id');
        }

        $out = [];
        $sellers = [];
        foreach ($picked as $c) {
            if (count($out) >= $limit || isset($sellers[$c->seller_profile_id])) {
                continue;
            }
            $sellers[$c->seller_profile_id] = true;
            $out[] = [
                'id' => $c->id,
                'body' => (string) $c->body,
                'buyer' => SellerRatings::maskBuyer($c->buyer),
                'initial' => mb_strtoupper(mb_substr($c->buyer->name, 0, 1)),
                'stars' => self::stars($c),
                'store' => ['name' => $c->seller->store_name, 'href' => $c->seller->path($locale)],
                'positiveRateBps' => $c->seller->stats?->positive_rate_bps,
                'createdAt' => $c->created_at->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * Latest visible comments of a store (public store page).
     *
     * @return list<array<string, mixed>>
     */
    public static function store(SellerProfile $seller, string $locale, int $limit = 10): array
    {
        return array_values(OrderComment::query()->visible()->where('seller_profile_id', $seller->id)->with(['buyer:id,name', 'order.item'])
            ->orderByDesc('created_at')->limit($limit)->get()->map(static fn (OrderComment $c): array => self::row($c, 'public', $locale))->all());
    }

    /** Stars shown on cards: the average of the given sub-scores, or 5 for a positive comment without scores. */
    private static function stars(OrderComment $c): int
    {
        $scores = array_filter([$c->score_delivery, $c->score_service, $c->score_description], static fn (?int $v): bool => $v !== null);

        return $scores === [] ? 5 : (int) round(array_sum($scores) / count($scores));
    }
}
