<?php

declare(strict_types=1);

namespace App\Shared\Ops;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

/**
 * Last run of each scheduled job for the Operations page (docs/ADMIN_PLAN.md item 13), kept in the cache by the
 * scheduler's own events: when it finished, how long it took, whether it failed or was skipped.
 */
final class ScheduleRuns
{
    public const string CACHE_KEY = 'ops:schedule-runs';

    public static function finished(ScheduledTaskFinished $event): void
    {
        self::remember($event->task, ['at' => now()->getTimestamp(), 'result' => $event->task->exitCode === 0 || $event->task->exitCode === null ? 'ok' : 'exit '.$event->task->exitCode, 'seconds' => round($event->runtime, 2)]);
    }

    public static function failed(ScheduledTaskFailed $event): void
    {
        self::remember($event->task, ['at' => now()->getTimestamp(), 'result' => 'failed: '.mb_substr($event->exception->getMessage(), 0, 200), 'seconds' => null]);
    }

    public static function skipped(ScheduledTaskSkipped $event): void
    {
        self::remember($event->task, ['at' => now()->getTimestamp(), 'result' => 'skipped', 'seconds' => null]);
    }

    /**
     * Every scheduled job with its frequency and last known run.
     *
     * @return list<array{command: string, cron: string, lastRun: string, result: string, seconds: float|null, late: bool}>
     */
    public static function overview(): array
    {
        /** @var array<string, array{at: int, result: string, seconds: float|null}> $runs */
        $runs = Cache::get(self::CACHE_KEY, []);
        $out = [];
        foreach (app(Schedule::class)->events() as $event) {
            $key = self::key($event);
            $run = $runs[$key] ?? null;
            $out[] = [
                'command' => $key,
                'cron' => $event->expression,
                'lastRun' => $run === null ? 'never' : now()->setTimestamp($run['at'])->diffForHumans(),
                'result' => $run['result'] ?? '—',
                'seconds' => $run['seconds'] ?? null,
                'late' => $run === null || ! str_starts_with($run['result'], 'ok') && $run['result'] !== 'skipped',
            ];
        }

        return $out;
    }

    /**
     * @param  array{at: int, result: string, seconds: float|null}  $run
     */
    private static function remember(ScheduledEvent $task, array $run): void
    {
        /** @var array<string, array{at: int, result: string, seconds: float|null}> $runs */
        $runs = Cache::get(self::CACHE_KEY, []);
        $runs[self::key($task)] = $run;
        Cache::forever(self::CACHE_KEY, $runs);
    }

    private static function key(ScheduledEvent $event): string
    {
        $command = (string) ($event->command ?? $event->description ?? 'closure');
        // "'/usr/bin/php' 'artisan' ledger:verify" → "ledger:verify"
        if (preg_match('/artisan["\']?\s+(.+)$/', $command, $m) === 1) {
            $command = trim($m[1]);
        }

        return mb_substr($command, 0, 120);
    }
}
