<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Tickets\Actions\AutoCloseTickets;
use Illuminate\Console\Command;

final class TicketsAutoclose extends Command
{
    protected $signature = 'tickets:autoclose';

    protected $description = 'Close tickets left without a user answer after ticket_autoclose_hours (idempotent)';

    public function handle(AutoCloseTickets $autoclose): int
    {
        $this->info("Closed {$autoclose->handle()} ticket(s).");

        return self::SUCCESS;
    }
}
