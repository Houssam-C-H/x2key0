<?php

declare(strict_types=1);

namespace App\Domains\Admin\Support;

use App\Domains\Admin\Actions\StaffNotes;
use App\Domains\Catalog\Models\Product;
use App\Domains\Offers\Models\Offer;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerStatusChange;
use App\Shared\Audit\AuditLog;
use App\Shared\Support\Translations;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One store on one page (docs/ADMIN_PLAN.md item 10): sales figures, merchant manager, payouts and Storage switches,
 * level and status history, the seller's API tokens (never the token values) and buyer price alerts on the products
 * this store sells, with staff notes.
 */
final class SellerOverview
{
    public function __construct(private readonly StaffNotes $notes) {}

    /**
     * @return array<string, mixed>
     */
    public function for(SellerProfile $profile): array
    {
        $profile->loadMissing(['user', 'level', 'merchantManager', 'stats']);
        $stats = $profile->stats;
        $since = now()->subDays(30);
        $paid30 = Order::query()->where('seller_profile_id', $profile->id)->whereNotNull('paid_at')->where('paid_at', '>=', $since);
        $levels = SellerLevel::query()->pluck('name', 'id');
        $levelName = fn (mixed $id): string => is_numeric($id) && isset($levels[(int) $id]) ? Translations::pick($levels[(int) $id], 'en') : '—';
        $pct = fn (?int $bps): string => $bps === null ? '—' : number_format($bps / 100, 1).' %';

        return [
            'summary' => [
                'Store' => $profile->store_name,
                'Owner' => $profile->user->username,
                'Status' => Format::enum($profile->status).($profile->status_reason !== null ? ' ('.$profile->status_reason.')' : ''),
                'Level' => $levelName($profile->seller_level_id).($profile->level_locked ? ' (locked by staff)' : ''),
                'Merchant manager' => $profile->merchantManager !== null ? $profile->merchantManager->username : 'none',
                'Payouts' => $profile->payouts_enabled ? 'on' : 'off',
                'Storage (vault)' => $profile->storage_enabled ? 'on' : 'off',
                'Country' => $profile->country ?? '—',
                'Activated' => Format::date($profile->activated_at),
                'On vacation until' => Format::date($profile->vacation_until),
            ],
            'ownerId' => $profile->user_id,
            'sales' => [
                'Sales, last 30 days' => Format::money((int) (clone $paid30)->sum('total_amount')),
                'Orders paid, last 30 days' => (string) (clone $paid30)->count(),
                'Open orders' => (string) Order::query()->where('seller_profile_id', $profile->id)->whereIn('status', [OrderStatus::Paid, OrderStatus::Delivered])->count(),
                'Orders completed (all time)' => (string) ($stats !== null ? $stats->orders_completed : 0),
                'Completion rate' => $pct($stats?->completion_rate_bps),
                'Positive rate (90 days)' => $pct($stats?->positive_rate_90d_bps),
                'Ratings' => (string) ($stats !== null ? $stats->rating_count : 0),
                'Disputes opened / lost' => ($stats !== null ? $stats->disputes_opened : 0).' / '.($stats !== null ? $stats->disputes_lost : 0),
                'Cancelled by seller' => (string) ($stats !== null ? $stats->orders_cancelled_by_seller : 0),
                'Average delivery' => $stats?->avg_delivery_minutes !== null ? $stats->avg_delivery_minutes.' min' : '—',
                'Active violation points' => (string) ($stats !== null ? $stats->violation_points : 0),
                'Live offers' => (string) Offer::query()->live()->where('seller_profile_id', $profile->id)->count(),
            ],
            'levelHistory' => AuditLog::query()
                ->where('subject_type', $profile->getMorphClass())->where('subject_id', $profile->id)
                ->latest('id')->limit(200)->get()
                ->filter(fn (AuditLog $log): bool => array_key_exists('seller_level_id', $log->after ?? []) || array_key_exists('level_locked', $log->after ?? []))
                ->take(15)
                ->map(fn (AuditLog $log): array => [
                    'at' => Format::date($log->created_at),
                    'change' => array_key_exists('seller_level_id', $log->after ?? [])
                        ? $levelName($log->before['seller_level_id'] ?? null).' → '.$levelName($log->after['seller_level_id'] ?? null)
                        : 'lock '.(($log->after['level_locked'] ?? false) ? 'on' : 'off'),
                    'by' => $log->actor_id === null ? 'level job' : '#'.$log->actor_id,
                ])->values()->all(),
            'statusHistory' => SellerStatusChange::query()->where('seller_profile_id', $profile->id)->latest('id')->limit(15)->get()
                ->map(fn (SellerStatusChange $c): array => [
                    'at' => Format::date($c->created_at),
                    'change' => Format::enum($c->from_status).' → '.Format::enum($c->to_status),
                    'reason' => (string) $c->reason,
                ])->all(),
            'tokens' => PersonalAccessToken::query()->where('tokenable_type', $profile->user->getMorphClass())->where('tokenable_id', $profile->user_id)->latest('id')->get()
                ->map(fn (PersonalAccessToken $t): array => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'abilities' => implode(', ', array_filter((array) $t->abilities, is_string(...))),
                    'lastUsed' => Format::date($t->last_used_at),
                    'created' => Format::date($t->created_at),
                ])->all(),
            'priceAlerts' => $this->priceAlerts($profile),
            'notes' => $this->notes->for('seller', $profile->id)->map(fn ($n): array => [
                'author' => $n->author->username,
                'at' => Format::date($n->created_at),
                'body' => $n->body,
            ])->all(),
        ];
    }

    /**
     * Buyer price alerts on the products this store sells: how many buyers wait, their best target and the store's
     * cheapest live price — a hint for pricing, never who the buyers are.
     *
     * @return list<array{product: string, alerts: int, lowestTarget: string, storePrice: string, below: int}>
     */
    private function priceAlerts(SellerProfile $profile): array
    {
        $rows = DB::table('offers')
            ->join('price_alerts', 'price_alerts.product_id', '=', 'offers.product_id')
            ->where('offers.seller_profile_id', $profile->id)
            ->whereIn('offers.id', Offer::query()->live()->where('seller_profile_id', $profile->id)->select('offers.id'))
            ->groupBy('offers.product_id')
            ->selectRaw('offers.product_id as product_id, COUNT(DISTINCT price_alerts.id) as alerts, MIN(price_alerts.target_amount) as lowest, MIN(offers.unit_price_amount) as price, COUNT(DISTINCT CASE WHEN price_alerts.target_amount < offers.unit_price_amount THEN price_alerts.id END) as below')
            ->orderByDesc('alerts')
            ->limit(15)
            ->get();

        // Names are JSON: fetched apart, because PostgreSQL cannot GROUP BY a json column.
        $names = Product::query()->whereIn('id', $rows->pluck('product_id')->filter()->all())->get()->mapWithKeys(fn (Product $p): array => [$p->id => $p->translate('name', 'en')]);

        return array_values($rows->map(fn (object $r): array => [
            'product' => is_numeric($r->product_id ?? null) && is_string($names[(int) $r->product_id] ?? null) ? $names[(int) $r->product_id] : '—',
            'alerts' => is_numeric($r->alerts ?? null) ? (int) $r->alerts : 0,
            'lowestTarget' => is_numeric($r->lowest ?? null) ? Format::money((int) $r->lowest) : '—',
            'storePrice' => is_numeric($r->price ?? null) ? Format::money((int) $r->price) : '—',
            'below' => is_numeric($r->below ?? null) ? (int) $r->below : 0,
        ])->all());
    }
}
