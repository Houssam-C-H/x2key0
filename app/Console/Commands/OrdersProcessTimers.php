<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Orders\Actions\ProcessOrderTimers;
use Illuminate\Console\Command;

final class OrdersProcessTimers extends Command
{
    protected $signature = 'orders:process-timers';

    protected $description = 'Apply due order timers: auto-cancel after a missed delivery SLA, auto-confirm after the confirmation window (idempotent)';

    public function handle(ProcessOrderTimers $timers): int
    {
        $result = $timers->handle();
        $this->info("Auto-cancelled {$result['auto_cancelled']}, auto-confirmed {$result['auto_confirmed']} order(s), closed {$result['closed']} timer(s).");

        return self::SUCCESS;
    }
}
