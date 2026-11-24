<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Support;

use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Affiliate\Models\AffiliateCommission;
use App\Domains\Affiliate\Models\AffiliateLink;
use App\Domains\Affiliate\Models\AffiliateReferral;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Support\Accounts;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Affiliate screens (spec Part 15): dashboard figures, My Affiliates, Alliance Bill (read from the ledger). */
final class AffiliatePresenter
{
    public const array BILL_TYPES = ['all', 'direct', 'indirect', 'video', 'reversal'];

    /**
     * @return array<string, mixed>
     */
    public static function dashboard(Affiliate $affiliate): array
    {
        $children = Affiliate::query()->where('parent_affiliate_id', $affiliate->id)->pluck('id');
        $commissions = AffiliateCommission::query()->where('affiliate_id', $affiliate->id);
        $earned = (clone $commissions)->sum(DB::raw('commission_amount - reversed_amount'));

        return [
            'code' => $affiliate->code,
            'status' => $affiliate->status,
            'rank' => $affiliate->rank,
            'link' => url('/r/'.$affiliate->code),
            'rates' => ['direct' => $affiliate->directBps(), 'level2' => $affiliate->level2Bps(), 'days' => $affiliate->attributionDays()],
            'balances' => [
                'pending' => Accounts::owed(AccountRef::user(AccountCode::AffiliatePending, $affiliate->user_id, 'USD')),
                'available' => Accounts::owed(AccountRef::user(AccountCode::AffiliateAvailable, $affiliate->user_id, 'USD')),
            ],
            'stats' => [
                'clicks' => $affiliate->clicks,
                'signups' => $affiliate->signups,
                'direct' => AffiliateReferral::query()->where('affiliate_id', $affiliate->id)->count(),
                'indirect' => $children->isEmpty() ? 0 : AffiliateReferral::query()->whereIn('affiliate_id', $children)->count(),
                'orders' => (clone $commissions)->count(),
                'earned' => (int) $earned,
            ],
            'links' => array_values(AffiliateLink::query()->where('affiliate_id', $affiliate->id)->orderByDesc('id')->get()->map(static fn (AffiliateLink $l): array => [
                'url' => url('/r/'.$affiliate->code.'/'.$l->slug),
                'target' => $l->target_path,
                'clicks' => $l->clicks,
                'signups' => $l->signups,
            ])->all()),
        ];
    }

    /**
     * My Affiliates: direct invitees (level 1) and their invitees (level 2), masked, with orders and commission.
     *
     * @return list<array<string, mixed>>
     */
    public static function referrals(Affiliate $affiliate, int $level): array
    {
        $owners = $level === 1 ? [$affiliate->id] : Affiliate::query()->where('parent_affiliate_id', $affiliate->id)->pluck('id')->all();
        if ($owners === []) {
            return [];
        }

        $referrals = AffiliateReferral::query()->with('user:id,name,country')->whereIn('affiliate_id', $owners)->orderByDesc('signed_up_at')->limit(200)->get();
        $userIds = $referrals->pluck('referred_user_id');

        $orders = [];
        $recent = [];
        foreach (Order::query()->whereIn('buyer_id', $userIds)->where('status', OrderStatus::Completed)->groupBy('buyer_id')
            ->selectRaw('buyer_id, COUNT(*) as n, MAX(completed_at) as last_at')->toBase()->get() as $row) {
            $id = is_numeric($row->buyer_id ?? null) ? (int) $row->buyer_id : 0;
            $orders[$id] = is_numeric($row->n ?? null) ? (int) $row->n : 0;
            $recent[$id] = is_string($row->last_at ?? null) ? Carbon::parse($row->last_at)->toIso8601String() : null;
        }

        $earned = [];
        foreach (AffiliateCommission::query()->where('affiliate_commissions.affiliate_id', $affiliate->id)->join('orders', 'orders.id', '=', 'affiliate_commissions.order_id')
            ->whereIn('orders.buyer_id', $userIds)->groupBy('orders.buyer_id')
            ->selectRaw('orders.buyer_id as buyer_id, SUM(affiliate_commissions.commission_amount - affiliate_commissions.reversed_amount) as total')->toBase()->get() as $row) {
            $id = is_numeric($row->buyer_id ?? null) ? (int) $row->buyer_id : 0;
            $earned[$id] = is_numeric($row->total ?? null) ? (int) $row->total : 0;
        }

        return array_values($referrals->map(static fn (AffiliateReferral $r): array => [
            'member' => self::mask($r->user->name),
            'country' => $r->user->country,
            'registeredAt' => $r->signed_up_at->toIso8601String(),
            'orders' => $orders[$r->referred_user_id] ?? 0,
            'recentAt' => $recent[$r->referred_user_id] ?? null,
            'commission' => $earned[$r->referred_user_id] ?? 0,
            'active' => $r->attribution_expires_at->isFuture(),
        ])->all());
    }

    /**
     * Alliance Bill for a month (spec Part 15): every affiliate journal on the user's affiliate accounts.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array{commission: int, rewards: int, reversals: int, net: int}}
     */
    public static function bill(Affiliate $affiliate, Carbon $from, Carbon $to, string $type): array
    {
        $types = [JournalType::AffiliateAccrual->value, JournalType::AffiliateVideoReward->value, JournalType::AffiliateReversal->value];

        $rows = DB::table('ledger_postings')
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.account_id')
            ->where('ledger_accounts.owner_type', 'user')->where('ledger_accounts.owner_id', $affiliate->user_id)
            ->whereIn('ledger_accounts.code', [AccountCode::AffiliatePending->value, AccountCode::AffiliateAvailable->value])
            ->whereIn('ledger_journals.type', $types)
            ->whereBetween('ledger_postings.created_at', [$from, $to])
            ->orderByDesc('ledger_postings.id')
            ->limit(500)
            ->get(['ledger_postings.amount', 'ledger_postings.created_at', 'ledger_journals.type', 'ledger_journals.source_type', 'ledger_journals.source_id']);

        $commissionIds = $rows->where('source_type', 'affiliate_commission')->pluck('source_id')->filter()->unique()->values();
        $commissions = AffiliateCommission::query()->with('order:id,number')->whereIn('id', $commissionIds)->get()->keyBy('id');

        $out = [];
        $totals = ['commission' => 0, 'rewards' => 0, 'reversals' => 0, 'net' => 0];
        foreach ($rows as $row) {
            $amount = is_numeric($row->amount) ? -(int) $row->amount : 0; // liabilities: credit (−) = earned
            $journal = is_string($row->type) ? $row->type : '';
            $commission = is_numeric($row->source_id) ? $commissions->get((int) $row->source_id) : null;
            $kind = match (true) {
                $journal === JournalType::AffiliateVideoReward->value => 'video',
                $journal === JournalType::AffiliateReversal->value => 'reversal',
                $commission?->level === 2 => 'indirect',
                default => 'direct',
            };

            match ($kind) {
                'video' => $totals['rewards'] += $amount,
                'reversal' => $totals['reversals'] += $amount,
                default => $totals['commission'] += $amount,
            };
            $totals['net'] += $amount;

            if ($type !== 'all' && $type !== $kind) {
                continue;
            }
            $out[] = [
                'date' => is_string($row->created_at) ? Carbon::parse($row->created_at)->toIso8601String() : null,
                'type' => $kind,
                'order' => $commission?->order->number,
                'amount' => $amount,
            ];
        }

        return ['rows' => $out, 'totals' => $totals];
    }

    /** "Samira" → "S****a" (spec: referred users are masked). */
    public static function mask(string $name): string
    {
        $len = mb_strlen($name);
        if ($len <= 2) {
            return mb_substr($name, 0, 1).'*';
        }

        return mb_substr($name, 0, 1).str_repeat('*', min(4, $len - 2)).mb_substr($name, -1);
    }
}
