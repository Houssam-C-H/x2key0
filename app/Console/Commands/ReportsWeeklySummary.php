<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Reporting\Actions\SendWeeklySummary;
use Illuminate\Console\Command;

/** `reports:weekly-summary`: e-mails last week's report to admins and finance, once per week (idempotent). */
final class ReportsWeeklySummary extends Command
{
    protected $signature = 'reports:weekly-summary';

    protected $description = 'E-mail last week\'s report (with CSV) to admins and finance, once per ISO week';

    public function handle(SendWeeklySummary $send): int
    {
        $count = $send->handle();
        $this->info($count === 0 ? 'Nothing sent (already sent this week, or no recipients).' : "Sent to {$count} staff member(s).");

        return self::SUCCESS;
    }
}
