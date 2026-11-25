<?php

declare(strict_types=1);

namespace App\Shared\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Operational health (spec Part 23 / M19 "alerts", docs/RUNBOOK.md): database and cache reachable, the scheduler
 * ran in the last 5 minutes (heartbeat written by `ops:heartbeat` every minute), no recent failed jobs, the last
 * `ledger:verify` was clean, no payment events stuck unprocessed. `ops:check` logs a CRITICAL line per failure —
 * route the `critical` level to Slack / e-mail / the pager (LOG_STACK) to get alerts.
 */
final class HealthCheck
{
    public const string HEARTBEAT_KEY = 'ops:scheduler-heartbeat';

    public const string LEDGER_KEY = 'ops:ledger-verify';

    /**
     * @return array<string, array{ok: bool, detail: string}>
     */
    public function run(): array
    {
        $checks = [];

        $checks['database'] = self::attempt(static fn (): string => DB::select('select 1 as ok') !== [] ? 'reachable' : 'no answer');
        $checks['cache'] = self::attempt(static function (): string {
            Cache::put('ops:ping', 'pong', 30);

            return Cache::get('ops:ping') === 'pong' ? 'reachable' : 'read-back failed';
        });

        $beat = Cache::get(self::HEARTBEAT_KEY);
        $checks['scheduler'] = is_int($beat) && $beat >= now()->subMinutes(5)->getTimestamp()
            ? ['ok' => true, 'detail' => 'last tick '.(now()->getTimestamp() - $beat).' s ago']
            : ['ok' => false, 'detail' => 'no tick in 5 minutes (is `schedule:run` in cron?)'];

        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
        $checks['queue'] = ['ok' => $failed === 0, 'detail' => "{$failed} failed job(s) in the last hour"];

        $ledger = Cache::get(self::LEDGER_KEY);
        $checks['ledger'] = is_array($ledger) && ($ledger['problems'] ?? 1) === 0
            ? ['ok' => true, 'detail' => 'last ledger:verify clean']
            : ['ok' => ! is_array($ledger), 'detail' => is_array($ledger) ? 'ledger:verify found problems' : 'ledger:verify has not run yet'];

        // Backups (ops:backup nightly): only where they are required (production by default).
        if (config()->boolean('x2key.ops.backups_required')) {
            $age = Backup::age();
            $max = config()->integer('x2key.ops.backup_max_age_hours') * 3600;
            $checks['backup'] = $age !== null && $age <= $max
                ? ['ok' => true, 'detail' => 'last backup '.intdiv($age, 3600).' h ago']
                : ['ok' => false, 'detail' => $age === null ? 'no backup recorded (is ops:backup scheduled?)' : 'last backup '.intdiv($age, 3600).' h ago'];
        }

        $stuck = DB::table('payment_events')->where('signature_valid', true)->whereNull('processed_at')->where('received_at', '<', now()->subMinutes(15))->count();
        $checks['payments'] = ['ok' => $stuck === 0, 'detail' => "{$stuck} payment event(s) unprocessed for 15 minutes"];

        return $checks;
    }

    /**
     * @param  array<string, array{ok: bool, detail: string}>  $checks
     */
    public static function healthy(array $checks): bool
    {
        return array_filter($checks, static fn (array $c): bool => ! $c['ok']) === [];
    }

    /**
     * Runs the checks and logs each failure at CRITICAL (alert routing).
     *
     * @return array<string, array{ok: bool, detail: string}>
     */
    public function alert(): array
    {
        $checks = $this->run();
        foreach ($checks as $name => $check) {
            if (! $check['ok']) {
                Log::critical("health check failed: {$name}", ['detail' => $check['detail']]);
            }
        }

        return $checks;
    }

    /**
     * @param  callable(): string  $probe
     * @return array{ok: bool, detail: string}
     */
    private static function attempt(callable $probe): array
    {
        try {
            return ['ok' => true, 'detail' => $probe()];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
