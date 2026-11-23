<?php

declare(strict_types=1);

namespace App\Http\Controllers\Buy;

use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Support\Accounts;
use App\Domains\Ledger\Support\Statements;
use App\Domains\Points\Support\Points;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Buy › Credits Balance, Points Record, Funding Details (spec 1.7 / 7.3): month statements read from the
 * ledgers. Credits cannot be topped up (D8); they come from refunds-to-credits and promotions.
 */
final class FinanceController
{
    private const string CURRENCY = 'USD';

    public function credits(Request $request, Statements $statements): Response
    {
        $user = self::user($request);
        [$month, $from, $to] = self::month($request);

        return Inertia::render('Buy/Credits', [
            'balance' => Accounts::owed(AccountRef::user(AccountCode::BuyerCredits, $user->id, self::CURRENCY)),
            'currency' => self::CURRENCY,
            'month' => $month,
            'months' => $statements->months($user),
            'rows' => array_reverse($statements->credits($user, self::CURRENCY, $from, $to)),
        ]);
    }

    public function points(Request $request, Statements $statements): Response
    {
        $user = self::user($request);
        [$month, $from, $to] = self::month($request);

        return Inertia::render('Buy/Points', [
            'points' => Points::balance($user->id),
            'month' => $month,
            'months' => $statements->months($user),
            'rows' => $statements->points($user, $from, $to),
        ]);
    }

    public function funding(Request $request, Statements $statements): Response
    {
        $user = self::user($request);
        [$month, $from, $to] = self::month($request);
        $type = self::type($request);
        $rows = $this->fundingRows($statements, $user, $from, $to, $type);

        return Inertia::render('Buy/Funding', [
            'month' => $month,
            'months' => $statements->months($user),
            'type' => $type,
            'inflow' => (int) array_sum(array_filter(array_column($rows, 'amount'), static fn (int $a): bool => $a > 0)),
            'outflow' => -(int) array_sum(array_filter(array_column($rows, 'amount'), static fn (int $a): bool => $a < 0)),
            'rows' => $rows,
            'currency' => self::CURRENCY,
        ]);
    }

    /** CSV export of the same statement (spec 7.3). */
    public function fundingCsv(Request $request, Statements $statements): StreamedResponse
    {
        $user = self::user($request);
        [$month, $from, $to] = self::month($request);
        $rows = $this->fundingRows($statements, $user, $from, $to, self::type($request));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['date', 'order', 'type', 'amount', 'currency']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['at'], self::safe($r['order'] ?? ''), $r['type'], number_format($r['amount'] / 100, 2, '.', ''), $r['currency']]);
            }
            fclose($out);
        }, "funding-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<array{type: string, order: string|null, amount: int, currency: string, at: string}>
     */
    private function fundingRows(Statements $statements, User $user, Carbon $from, Carbon $to, ?string $type): array
    {
        return array_values(array_filter(
            $statements->funding($user, self::CURRENCY, $from, $to),
            static fn (array $r): bool => $type === null || ($type === 'in' ? $r['amount'] > 0 : $r['amount'] < 0),
        ));
    }

    /**
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    private static function month(Request $request): array
    {
        $m = $request->query('month');
        $start = is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1 ? Carbon::createFromFormat('Y-m-d', "{$m}-01") : null;
        $start = ($start ?? now())->copy()->startOfMonth();

        return [$start->format('Y-m'), $start->copy(), $start->copy()->endOfMonth()];
    }

    private static function type(Request $request): ?string
    {
        return in_array($t = $request->query('type'), ['in', 'out'], true) ? $t : null;
    }

    /** CSV injection guard: a cell must not start like a formula. */
    private static function safe(string $value): string
    {
        return $value !== '' && str_contains('=+-@', $value[0]) ? "'{$value}" : $value;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
