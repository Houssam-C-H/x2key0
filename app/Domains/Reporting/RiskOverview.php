<?php

declare(strict_types=1);

namespace App\Domains\Reporting;

use App\Domains\Orders\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * Fraud dashboard (admin › Risk overview): open risk flags by rule and the latest ones, sellers ranked by
 * chargebacks and disputes over the last 90 days, and stores holding keys frozen as duplicates of another
 * seller's (storage_items.flagged_at). Read-only aggregates; every figure links to its queue in the admin.
 */
final class RiskOverview
{
    public const int WINDOW_DAYS = 90;

    /**
     * @return array{flagsByRule: list<array{rule: string, open: int}>, recentFlags: list<array<string, mixed>>, sellers: list<array<string, mixed>>, duplicateKeys: list<array{store: string, items: int}>, totals: array<string, int>}
     */
    public function summary(): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $flagsByRule = DB::table('risk_flags')->where('status', 'open')
            ->selectRaw('rule, COUNT(*) as n')->groupBy('rule')->orderByDesc('n')->get()
            ->map(static fn (object $r): array => ['rule' => (string) (is_scalar($r->rule ?? null) ? $r->rule : ''), 'open' => self::int($r->n ?? 0)])->values()->all();

        $recentFlags = DB::table('risk_flags')->where('status', 'open')->orderByDesc('id')->limit(15)
            ->get(['id', 'subject_type', 'subject_id', 'rule', 'score', 'created_at'])
            ->map(static fn (object $r): array => [
                'id' => self::int($r->id ?? 0),
                'subject' => (is_scalar($r->subject_type ?? null) ? (string) $r->subject_type : '').' #'.self::int($r->subject_id ?? 0),
                'rule' => is_scalar($r->rule ?? null) ? (string) $r->rule : '',
                'score' => self::int($r->score ?? 0),
                'at' => is_scalar($r->created_at ?? null) ? (string) $r->created_at : '',
            ])->values()->all();

        $orders = DB::table('orders')->whereNotNull('paid_at')->where('paid_at', '>=', $since)
            ->selectRaw('seller_profile_id, COUNT(*) as n')->groupBy('seller_profile_id')->pluck('n', 'seller_profile_id');
        $disputes = DB::table('disputes')->join('orders', 'orders.id', '=', 'disputes.order_id')->where('disputes.created_at', '>=', $since)
            ->selectRaw('orders.seller_profile_id as sid, COUNT(*) as n')->groupBy('orders.seller_profile_id')->pluck('n', 'sid');
        $chargebacks = DB::table('chargebacks')->join('orders', 'orders.id', '=', 'chargebacks.order_id')->where('chargebacks.created_at', '>=', $since)
            ->selectRaw('orders.seller_profile_id as sid, COUNT(*) as n')->groupBy('orders.seller_profile_id')->pluck('n', 'sid');
        $refunded = DB::table('orders')->where('status', OrderStatus::Refunded->value)->where('updated_at', '>=', $since)
            ->selectRaw('seller_profile_id, COUNT(*) as n')->groupBy('seller_profile_id')->pluck('n', 'seller_profile_id');

        $ids = array_values(array_unique(array_merge(array_keys($disputes->all()), array_keys($chargebacks->all()))));
        $stores = DB::table('seller_profiles')->whereIn('id', $ids === [] ? [0] : $ids)->pluck('store_name', 'id');

        $sellers = [];
        foreach ($ids as $id) {
            $n = self::int($orders[$id] ?? 0);
            $cb = self::int($chargebacks[$id] ?? 0);
            $dp = self::int($disputes[$id] ?? 0);
            $sellers[] = [
                'id' => self::int($id),
                'store' => is_scalar($stores[$id] ?? null) ? (string) $stores[$id] : '#'.$id,
                'orders' => $n,
                'chargebacks' => $cb,
                'disputes' => $dp,
                'refunded' => self::int($refunded[$id] ?? 0),
                'chargebackBps' => $n === 0 ? null : intdiv($cb * 10000, $n),
                'disputeBps' => $n === 0 ? null : intdiv($dp * 10000, $n),
            ];
        }
        usort($sellers, static fn (array $a, array $b): int => [$b['chargebacks'], $b['disputeBps'] ?? 0] <=> [$a['chargebacks'], $a['disputeBps'] ?? 0]);

        $duplicateKeys = DB::table('storage_items')->whereNotNull('storage_items.flagged_at')
            ->join('seller_profiles', 'seller_profiles.id', '=', 'storage_items.seller_profile_id')
            ->selectRaw('seller_profiles.store_name as store, COUNT(*) as n')->groupBy('seller_profiles.store_name')->orderByDesc('n')->limit(15)->get()
            ->map(static fn (object $r): array => ['store' => is_scalar($r->store ?? null) ? (string) $r->store : '', 'items' => self::int($r->n ?? 0)])->values()->all();

        return [
            'flagsByRule' => array_values($flagsByRule),
            'recentFlags' => array_values($recentFlags),
            'sellers' => array_slice($sellers, 0, 15),
            'duplicateKeys' => array_values($duplicateKeys),
            'totals' => [
                'openFlags' => array_sum(array_column($flagsByRule, 'open')),
                'chargebacks' => array_sum(array_map(self::int(...), $chargebacks->all())),
                'disputes' => array_sum(array_map(self::int(...), $disputes->all())),
                'frozenKeys' => array_sum(array_column($duplicateKeys, 'items')),
                'blocklist' => DB::table('blocklist')->count(),
            ],
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
