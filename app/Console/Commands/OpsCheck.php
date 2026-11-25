<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Ops\HealthCheck;
use App\Shared\Ops\OpsAlerts;
use Illuminate\Console\Command;

/** `ops:check` (every 5 minutes, spec M19 "alerts"): health checks, CRITICAL log line per failure, alerts by e-mail / Slack (OpsAlerts). */
final class OpsCheck extends Command
{
    protected $signature = 'ops:check';

    protected $description = 'Run the health checks and log CRITICAL for each failure (alerts)';

    public function handle(HealthCheck $health, OpsAlerts $alerts): int
    {
        $checks = $health->alert();
        foreach ($alerts->notify($checks) as $sent) {
            $this->warn('alert sent: '.$sent);
        }
        foreach ($checks as $name => $check) {
            $this->line(($check['ok'] ? 'OK   ' : 'FAIL ').$name.': '.$check['detail']);
        }

        return HealthCheck::healthy($checks) ? self::SUCCESS : self::FAILURE;
    }
}
