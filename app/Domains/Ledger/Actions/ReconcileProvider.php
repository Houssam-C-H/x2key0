<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Actions;

use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `ledger:reconcile {provider} {date}` (spec 7.4, daily): captured payments of the provider that day
 * versus what the ledger put into PSP_CLEARING:{provider} for them. With real PSPs (M6b) the provider's
 * settlement report becomes the third column. Read-only.
 */
final class ReconcileProvider
{
    /**
     * @return array{payments: array<string, int>, ledger: array<string, int>, missing: list<string>}
     */
    public function handle(string $provider, Carbon $day): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $captured = Payment::query()
            ->where('provider', $provider)
            ->where('status', PaymentStatus::Captured)
            ->whereBetween('captured_at', [$from, $to])
            ->get(['id', 'order_id', 'amount', 'currency', 'public_id']);

        $payments = [];
        foreach ($captured as $payment) {
            $payments[$payment->currency] = ($payments[$payment->currency] ?? 0) + $payment->amount;
        }

        $ledgerRows = DB::table('ledger_postings')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.account_id')
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_postings.journal_id')
            ->where('ledger_accounts.code', AccountCode::PspClearing->value.':'.$provider)
            ->whereIn('ledger_journals.type', [JournalType::PaymentCaptured->value, JournalType::LateCapture->value])
            ->whereBetween('ledger_journals.posted_at', [$from, $to])
            ->select('ledger_postings.currency')
            ->selectRaw('SUM(ledger_postings.amount) as total')
            ->groupBy('ledger_postings.currency')
            ->get();

        $ledger = [];
        foreach ($ledgerRows as $row) {
            $currency = is_string($row->currency) ? $row->currency : '';
            $ledger[$currency] = is_numeric($row->total) ? (int) $row->total : 0;
        }

        // Captured payments whose journal is missing (order still in a state that never posted, or a bug).
        $missing = [];
        foreach ($captured as $payment) {
            $posted = DB::table('ledger_journals')->whereIn('idempotency_key', ["PAYMENT_CAPTURED:order:{$payment->order_id}", "LATE_CAPTURE:payment:{$payment->id}"])->exists();
            if (! $posted) {
                $missing[] = $payment->public_id;
            }
        }

        return ['payments' => $payments, 'ledger' => $ledger, 'missing' => $missing];
    }
}
