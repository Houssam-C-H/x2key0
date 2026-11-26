<?php

declare(strict_types=1);

namespace App\Shared\Realtime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends live signals after the surrounding transaction commits. A broadcast failure (Reverb down) is logged and
 * never breaks the business action; browsers fall back to counters refreshed on page load.
 */
final class Signals
{
    /** Staff queue signals are merged: at most one per this many seconds. */
    public const int STAFF_DEBOUNCE_SECONDS = 2;

    public static function user(int $userId, string $kind): void
    {
        DB::afterCommit(static function () use ($userId, $kind): void {
            self::send(static fn () => UserSignal::dispatch($userId, $kind), ['user' => $userId, 'kind' => $kind]);
        });
    }

    public static function staffQueues(): void
    {
        DB::afterCommit(static function (): void {
            if (! Cache::add('realtime.staff-queues', 1, self::STAFF_DEBOUNCE_SECONDS)) {
                return;
            }
            self::send(static fn () => StaffQueuesChanged::dispatch(), ['channel' => 'staff']);
        });
    }

    /**
     * @param  array<string, int|string>  $context
     */
    private static function send(callable $dispatch, array $context): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            Log::warning('Live signal failed', [...$context, 'error' => $e->getMessage()]);
        }
    }
}
