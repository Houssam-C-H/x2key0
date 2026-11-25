<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Ops\HealthCheck;
use Illuminate\Console\Command;

/** `ops:check` (every 5 minutes, spec M19 "alerts"): health checks, CRITICAL log line per failure. */
final class OpsCheck extends Command
{
    protected $signature = 'ops:check';

    protected $description = 'Run the health checks and log CRITICAL for each failure (alerts)';

    public function handle(HealthCheck $health): int
    {
        $checks = $health->alert();
        foreach ($checks as $name => $check) {
            $this->line(($check['ok'] ? 'OK   ' : 'FAIL ').$name.': '.$check['detail']);
        }

        return HealthCheck::healthy($checks) ? self::SUCCESS : self::FAILURE;
    }
}
