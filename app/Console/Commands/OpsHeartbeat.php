<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Ops\HealthCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** `ops:heartbeat` (every minute): the health check uses it to tell that `schedule:run` is running. */
final class OpsHeartbeat extends Command
{
    protected $signature = 'ops:heartbeat';

    protected $description = 'Record that the scheduler is alive (every minute)';

    public function handle(): int
    {
        Cache::put(HealthCheck::HEARTBEAT_KEY, now()->getTimestamp(), now()->addHour());

        return self::SUCCESS;
    }
}
