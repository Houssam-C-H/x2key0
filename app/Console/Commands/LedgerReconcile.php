<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Ledger\Actions\ReconcileProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class LedgerReconcile extends Command
{
    protected $signature = 'ledger:reconcile {provider} {date? : YYYY-MM-DD, default yesterday}';

    protected $description = 'Compare captured payments of a provider with the PSP clearing postings for a day; read-only';

    public function handle(ReconcileProvider $reconcile): int
    {
        $provider = (string) $this->argument('provider');
        $date = $this->argument('date');
        $day = is_string($date) ? Carbon::createFromFormat('Y-m-d', $date) : now()->subDay();

        if (! $day instanceof Carbon) {
            $this->error('Usage: ledger:reconcile {provider} {YYYY-MM-DD}');

            return self::INVALID;
        }

        $result = $reconcile->handle($provider, $day);
        $currencies = array_unique([...array_keys($result['payments']), ...array_keys($result['ledger'])]);
        $ok = $result['missing'] === [];

        foreach ($currencies as $currency) {
            $p = $result['payments'][$currency] ?? 0;
            $l = $result['ledger'][$currency] ?? 0;
            $ok = $ok && $p === $l;
            $this->line(sprintf('%s  payments %d  ledger %d  %s', $currency, $p, $l, $p === $l ? 'OK' : 'MISMATCH'));
        }
        foreach ($result['missing'] as $payment) {
            $this->error("Captured payment {$payment} has no journal.");
        }

        if (! $ok) {
            Log::critical('ledger:reconcile mismatch', ['provider' => $provider, 'day' => $day->toDateString(), 'result' => $result]);

            return self::FAILURE;
        }

        $this->info("{$provider} {$day->toDateString()} reconciled.");

        return self::SUCCESS;
    }
}
