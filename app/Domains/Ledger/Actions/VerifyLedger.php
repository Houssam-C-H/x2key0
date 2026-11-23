<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Actions;

use App\Domains\Ledger\Enums\AccountType;
use Illuminate\Support\Facades\DB;

/**
 * `ledger:verify` (spec 7.4, nightly): recomputes everything from postings and reports what disagrees.
 * Read-only, so it can run any time and any number of times.
 *
 * - every money journal sums to zero per currency, every points journal sums to zero;
 * - every posting is in its account's currency;
 * - every stored balance equals the sum of its account's postings (money and points);
 * - user-owned liabilities owe the user ≥ 0 (a negative one is reported: only clawbacks may cause it).
 */
final class VerifyLedger
{
    /**
     * @return list<string> problems found (empty = consistent)
     */
    public function handle(): array
    {
        $problems = [];

        $unbalanced = DB::table('ledger_postings')->select('journal_id', 'currency')->selectRaw('SUM(amount) as total')
            ->groupBy('journal_id', 'currency')->havingRaw('SUM(amount) <> 0')->get();
        foreach ($unbalanced as $row) {
            $problems[] = sprintf('Journal %s sums to %s %s.', self::str($row->journal_id), self::str($row->total), self::str($row->currency));
        }

        $wrongCurrency = DB::table('ledger_postings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.account_id')
            ->whereColumn('ledger_postings.currency', '!=', 'ledger_accounts.currency')->count();
        if ($wrongCurrency > 0) {
            $problems[] = "{$wrongCurrency} posting(s) are not in their account's currency.";
        }

        $problems = [...$problems, ...self::projection('ledger')];

        $owedNegative = DB::table('ledger_accounts')->join('ledger_balances', 'ledger_balances.account_id', '=', 'ledger_accounts.id')
            ->where('ledger_accounts.owner_type', 'user')->where('ledger_accounts.type', AccountType::Liability->value)
            ->where('ledger_balances.balance', '>', 0)->get(['ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.owner_id', 'ledger_balances.balance']);
        foreach ($owedNegative as $row) {
            $problems[] = sprintf('Account %s (%s of user %s) is overdrawn by %s.', self::str($row->id), self::str($row->code), self::str($row->owner_id), self::str($row->balance));
        }

        $points = DB::table('points_postings')->select('journal_id')->selectRaw('SUM(amount) as total')
            ->groupBy('journal_id')->havingRaw('SUM(amount) <> 0')->get();
        foreach ($points as $row) {
            $problems[] = sprintf('Points journal %s sums to %s.', self::str($row->journal_id), self::str($row->total));
        }

        return [...$problems, ...self::projection('points')];
    }

    /**
     * Stored balances against the sum of postings, for the money or the points ledger.
     *
     * @return list<string>
     */
    private static function projection(string $ledger): array
    {
        [$accounts, $balances, $postings, $label] = $ledger === 'points'
            ? ['points_accounts', 'points_balances', 'points_postings', 'Points']
            : ['ledger_accounts', 'ledger_balances', 'ledger_postings', 'Ledger'];

        $stored = DB::table($balances)->pluck('balance', 'account_id');
        $computed = DB::table($postings)->select('account_id')->selectRaw('SUM(amount) as total')->groupBy('account_id')->pluck('total', 'account_id');

        $problems = [];
        foreach (DB::table($accounts)->pluck('id') as $id) {
            $s = self::int($stored[$id] ?? 0);
            $c = self::int($computed[$id] ?? 0);
            if ($s !== $c) {
                $problems[] = sprintf('%s account %s: stored balance %d, postings sum %d.', $label, self::str($id), $s, $c);
            }
        }

        return $problems;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
