<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Support;

use App\Domains\Offers\Models\Offer;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seller analytics (Seller Center › Analytics) for the last N days: daily sales, totals (orders, sales, net),
 * offer-page visitors and conversion, top offers, average delivery time, and each live offer's price against the
 * cheapest competing live offer (same product, or same game×category and service type).
 */
final class SellerAnalytics
{
    /**
     * @return array<string, mixed>
     */
    public static function for(SellerProfile $profile, int $days = 30): array
    {
        $from = Carbon::today()->subDays($days - 1);

        $daily = DB::table('orders')->where('seller_profile_id', $profile->id)->whereNotNull('paid_at')->where('paid_at', '>=', $from)
            ->selectRaw('DATE(paid_at) as d, COUNT(*) as n, COALESCE(SUM(total_amount), 0) as sales, COALESCE(SUM(seller_net_amount), 0) as net')
            ->groupByRaw('DATE(paid_at)')->get()
            ->keyBy(static fn (object $r): string => is_scalar($r->d ?? null) ? substr((string) $r->d, 0, 10) : '');

        $series = [];
        for ($d = $from->copy(); $d->lte(Carbon::today()); $d->addDay()) {
            $row = $daily->get($d->toDateString());
            $series[] = [
                'day' => $d->toDateString(),
                'orders' => self::int($row->n ?? 0),
                'sales' => self::int($row->sales ?? 0),
            ];
        }

        $offerIds = Offer::query()->where('seller_profile_id', $profile->id)->pluck('id')->all();
        $visitors = $offerIds === [] ? 0 : DB::table('browsing_history')->whereIn('offer_id', $offerIds)->where('viewed_at', '>=', $from)->count();
        $orders = array_sum(array_column($series, 'orders'));

        $top = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->join('offers', 'offers.id', '=', 'order_items.offer_id')
            ->where('orders.seller_profile_id', $profile->id)->whereNotNull('orders.paid_at')->where('orders.paid_at', '>=', $from)
            ->selectRaw('offers.id as id, offers.title as title, SUM(order_items.quantity) as units, COALESCE(SUM(orders.total_amount), 0) as sales, COUNT(DISTINCT orders.id) as n')
            ->groupBy('offers.id', 'offers.title')->orderByDesc('sales')->limit(5)->get()
            ->map(static fn (object $r): array => [
                'title' => is_scalar($r->title ?? null) ? (string) $r->title : '',
                'orders' => self::int($r->n ?? 0),
                'units' => self::int($r->units ?? 0),
                'sales' => self::int($r->sales ?? 0),
            ])->values()->all();

        $profile->loadMissing('stats');

        return [
            'days' => $days,
            'series' => $series,
            'totals' => [
                'orders' => $orders,
                'sales' => array_sum(array_column($series, 'sales')),
                'net' => self::int($daily->sum('net')),
                'visitors' => $visitors,
                'conversionBps' => $visitors === 0 ? null : intdiv(min($orders, $visitors) * 10000, $visitors),
                'avgDeliveryMinutes' => $profile->stats?->avg_delivery_minutes,
            ],
            'topOffers' => array_values($top),
            'pricing' => self::pricing($profile),
        ];
    }

    /**
     * Live offers of the store with the cheapest competing live price.
     *
     * @return list<array{title: string, href: string, price: int, lowestOther: int|null, position: string}>
     */
    private static function pricing(SellerProfile $profile): array
    {
        $rows = [];
        $offers = Offer::query()->live()->where('offers.seller_profile_id', $profile->id)->orderByDesc('offers.sold_count')->limit(20)->get(['offers.*']);

        foreach ($offers as $offer) {
            $competitors = Offer::query()->live()->where('offers.seller_profile_id', '!=', $profile->id);
            $competitors = $offer->product_id !== null
                ? $competitors->where('offers.product_id', $offer->product_id)
                : $competitors->where('offers.game_category_id', $offer->game_category_id)->whereNull('offers.product_id')
                    ->where(fn ($q) => $offer->service_type_id === null ? $q->whereNull('offers.service_type_id') : $q->where('offers.service_type_id', $offer->service_type_id));
            $min = $competitors->min('offers.unit_price_amount');
            $lowest = is_numeric($min) ? (int) $min : null;

            $rows[] = [
                'title' => $offer->title,
                'href' => $offer->path('en'),
                'price' => $offer->unit_price_amount,
                'lowestOther' => $lowest,
                'position' => match (true) {
                    $lowest === null => 'alone',
                    $offer->unit_price_amount <= $lowest => 'cheapest',
                    default => 'above',
                },
            ];
        }

        return $rows;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
