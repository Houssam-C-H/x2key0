<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Disputes\Actions\EscalateOverdueDisputes;
use Illuminate\Console\Command;

final class DisputesEscalateOverdue extends Command
{
    protected $signature = 'disputes:escalate-overdue';

    protected $description = 'Send disputes whose counterparty missed the response deadline to staff (idempotent)';

    public function handle(EscalateOverdueDisputes $escalate): int
    {
        $this->info("Escalated {$escalate->handle()} dispute(s).");

        return self::SUCCESS;
    }
}
