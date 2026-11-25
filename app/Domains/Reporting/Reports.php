<?php

declare(strict_types=1);

namespace App\Domains\Reporting;

use App\Domains\Catalog\Models\Game;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Orders\Enums\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin reports (spec Part 18 "Reports", 17.1 daily metrics) for a date range, read from orders and the ledger:
 *
 * - GMV = total paid by buyers on orders paid in the range; take rate = (commission + buyer fees) / GMV;
 * - refunds (amount, rate of GMV), chargebacks (count, amount, ratio of paid orders), disputes (rate of paid orders);
 * - coupon cost (EXP_PROMOTIONS) and affiliate cost (EXP_AFFILIATE) from the ledger in the range;
 * - margin per category type, top games and top sellers by GMV;
 * - queues staff must work (payment reviews, risk flags, escalated disputes, open tickets, certifications,
 *   offers waiting for review, withdrawals to approve, campaign applications, affiliate videos).
 */
final class Reports
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $paid = DB::table('orders')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to]);

        $totals = (clone $paid)->selectRaw('COUNT(*) as n, COALESCE(SUM(total_amount), 0) as gmv, COALESCE(SUM(commission_amount), 0) as commission, '
            .'COALESCE(SUM(buyer_fee_amount + addon_fee_amount + method_fee_amount), 0) as fees, '
            .'COALESCE(SUM(refunded_credits_amount + refunded_psp_amount), 0) as refunds')->first();
        $n = self::int($totals->n ?? 0);
        $gmv = self::int($totals->gmv ?? 0);
        $revenue = self::int($totals->commission ?? 0) + self::int($totals->fees ?? 0);
        $refunds = self::int($totals->refunds ?? 0);

        $completed = (clone $paid)->where('status', OrderStatus::Completed->value)->count();
        $chargebacks = DB::table('chargebacks')->whereBetween('created_at', [$from, $to])->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as amount')->first();
        $disputes = DB::table('disputes')->whereBetween('created_at', [$from, $to])->count();

        return [
            'orders' => $n,
            'completed' => $completed,
            'gmv' => $gmv,
            'revenue' => $revenue,
            'takeRateBps' => $gmv === 0 ? null : intdiv($revenue * 10000, $gmv),
            'refunds' => $refunds,
            'refundRateBps' => $gmv === 0 ? null : intdiv($refunds * 10000, $gmv),
            'chargebacks' => self::int($chargebacks->n ?? 0),
            'chargebackAmount' => self::int($chargebacks->amount ?? 0),
            'chargebackRatioBps' => $n === 0 ? null : intdiv(self::int($chargebacks->n ?? 0) * 10000, $n),
            'disputeRateBps' => $n === 0 ? null : intdiv($disputes * 10000, $n),
            'couponCost' => $this->expense(AccountCode::ExpPromotions, $from, $to),
            'affiliateCost' => $this->expense(AccountCode::ExpAffiliate, $from, $to),
            'byType' => $this->byType($from, $to),
            'topGames' => $this->top($from, $to),
            'topSellers' => $this->topSellers($from, $to),
            'queues' => $this->queues(),
        ];
    }

    /**
     * Net expense posted to a platform expense account in the range (positive = cost).
     */
    private function expense(AccountCode $code, Carbon $from, Carbon $to): int
    {
        $sum = DB::table('ledger_postings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.account_id')
            ->where('ledger_accounts.code', $code->value)->where('ledger_accounts.owner_type', 'platform')
            ->whereBetween('ledger_postings.created_at', [$from, $to])->sum('ledger_postings.amount');

        return self::int($sum);
    }

    /**
     * GMV and platform revenue (margin) per category type.
     *
     * @return list<array{type: string, orders: int, gmv: int, revenue: int, marginBps: int|null}>
     */
    private function byType(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('offers', 'offers.id', '=', 'order_items.offer_id')
            ->join('game_categories', 'game_categories.id', '=', 'offers.game_category_id')
            ->join('category_types', 'category_types.id', '=', 'game_categories.category_type_id')
            ->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
            ->groupBy('category_types.code')
            ->selectRaw('category_types.code as type, COUNT(*) as n, COALESCE(SUM(orders.total_amount), 0) as gmv, '
                .'COALESCE(SUM(orders.commission_amount + orders.buyer_fee_amount + orders.addon_fee_amount + orders.method_fee_amount), 0) as revenue')
            ->orderByDesc('gmv')->get();

        return array_values($rows->map(static function (object $r): array {
            $gmv = self::int($r->gmv ?? 0);
            $revenue = self::int($r->revenue ?? 0);

            return ['type' => is_string($r->type ?? null) ? $r->type : '', 'orders' => self::int($r->n ?? 0), 'gmv' => $gmv, 'revenue' => $revenue, 'marginBps' => $gmv === 0 ? null : intdiv($revenue * 10000, $gmv)];
        })->all());
    }

    /**
     * @return list<array{name: string, orders: int, gmv: int}>
     */
    private function top(Carbon $from, Carbon $to): array
    {
        // Grouped by id only: PostgreSQL cannot group by the JSON name column.
        $rows = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('offers', 'offers.id', '=', 'order_items.offer_id')
            ->join('game_categories', 'game_categories.id', '=', 'offers.game_category_id')
            ->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
            ->groupBy('game_categories.game_id')
            ->selectRaw('game_categories.game_id as id, COUNT(*) as n, COALESCE(SUM(orders.total_amount), 0) as gmv')
            ->orderByDesc('gmv')->limit(10)->get();

        $names = Game::query()->whereIn('id', $rows->pluck('id'))->get()->mapWithKeys(static fn (Game $g): array => [$g->id => $g->translate('name', 'en')]);

        return array_values($rows->map(static function (object $r) use ($names): array {
            $id = self::int($r->id ?? 0);

            return ['name' => (string) ($names[$id] ?? '#'.$id), 'orders' => self::int($r->n ?? 0), 'gmv' => self::int($r->gmv ?? 0)];
        })->all());
    }

    /**
     * @return list<array{name: string, orders: int, gmv: int, disputes: int}>
     */
    private function topSellers(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('orders')->join('seller_profiles', 'seller_profiles.id', '=', 'orders.seller_profile_id')
            ->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$from, $to])
            ->groupBy('seller_profiles.id', 'seller_profiles.store_name')
            ->selectRaw('seller_profiles.id as id, seller_profiles.store_name as name, COUNT(*) as n, COALESCE(SUM(orders.total_amount), 0) as gmv')
            ->orderByDesc('gmv')->limit(10)->get();

        $disputes = DB::table('disputes')->join('orders', 'orders.id', '=', 'disputes.order_id')->whereBetween('disputes.created_at', [$from, $to])
            ->groupBy('orders.seller_profile_id')->selectRaw('orders.seller_profile_id as id, COUNT(*) as n')->pluck('n', 'id');

        return array_values($rows->map(static fn (object $r): array => [
            'name' => is_string($r->name ?? null) ? $r->name : '',
            'orders' => self::int($r->n ?? 0),
            'gmv' => self::int($r->gmv ?? 0),
            'disputes' => self::int($disputes[$r->id ?? 0] ?? 0),
        ])->all());
    }

    /**
     * @return array<string, int>
     */
    public function queues(): array
    {
        return [
            'payment_review' => DB::table('orders')->where('status', OrderStatus::PaymentReview->value)->count(),
            'risk_flags' => DB::table('risk_flags')->where('status', 'open')->count(),
            'disputes_escalated' => DB::table('disputes')->where('status', 'escalated')->count(),
            'tickets_pending' => DB::table('tickets')->where('status', 'pending')->count(),
            'certifications' => DB::table('certifications')->where('status', 'pending')->count(),
            'offers_review' => DB::table('offers')->where('status', 'pending_review')->count(),
            'withdrawals' => DB::table('withdrawal_requests')->where('status', 'requested')->count(),
            'campaign_applications' => DB::table('campaign_participations')->where('status', 'applied')->count(),
            'affiliate_videos' => DB::table('affiliate_video_records')->where('status', 'submitted')->count(),
            'ledger_adjustments' => DB::table('ledger_adjustments')->where('status', 'pending')->count(),
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
