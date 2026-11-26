<?php

declare(strict_types=1);

namespace App\Domains\Affiliate\Support;

use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly affiliate bills for staff (docs/ADMIN_PLAN.md item 8): per affiliate, what the ledger booked on their
 * affiliate accounts in one month — commissions earned, video rewards, reversals and transfers to Credits. Read from
 * the ledger, so it always matches the money (the per-affiliate detail is AffiliatePresenter::bill).
 */
final class AffiliateBills
{
    /**
     * @return list<array{userId: int, username: string, commission: int, rewards: int, reversals: int, net: int, transferred: int}>
     */
    public static function month(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $earning = [JournalType::AffiliateAccrual->value, JournalType::AffiliateVideoReward->value, JournalType::AffiliateReversal->value, JournalType::AffiliateToCredits->value];

        $rows = DB::table('ledger_postings')
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.account_id')
            ->where('ledger_accounts.owner_type', 'user')
            ->whereIn('ledger_accounts.code', [AccountCode::AffiliatePending->value, AccountCode::AffiliateAvailable->value])
            ->whereIn('ledger_journals.type', $earning)
            ->whereBetween('ledger_postings.created_at', [$from, $to])
            ->groupBy('ledger_accounts.owner_id', 'ledger_journals.type')
            ->selectRaw('ledger_accounts.owner_id as owner_id, ledger_journals.type as type, SUM(ledger_postings.amount) as total')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $owner = is_numeric($row->owner_id) ? (int) $row->owner_id : 0;
            $earned = is_numeric($row->total) ? -(int) $row->total : 0; // liabilities: credit (−) = owed to the affiliate
            $out[$owner] ??= ['userId' => $owner, 'username' => '', 'commission' => 0, 'rewards' => 0, 'reversals' => 0, 'net' => 0, 'transferred' => 0];

            match ($row->type) {
                JournalType::AffiliateAccrual->value => $out[$owner]['commission'] += $earned,
                JournalType::AffiliateVideoReward->value => $out[$owner]['rewards'] += $earned,
                JournalType::AffiliateReversal->value => $out[$owner]['reversals'] += $earned,
                default => $out[$owner]['transferred'] += -$earned,
            };
        }

        $names = DB::table('users')->whereIn('id', array_keys($out))->pluck('username', 'id');
        foreach ($out as $owner => $line) {
            $out[$owner]['username'] = is_string($names[$owner] ?? null) ? $names[$owner] : '#'.$owner;
            $out[$owner]['net'] = $line['commission'] + $line['rewards'] + $line['reversals'];
        }

        usort($out, fn (array $a, array $b): int => $b['net'] <=> $a['net']);

        return $out;
    }
}
