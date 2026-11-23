<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Violations\Actions\ProcessViolations;
use Illuminate\Console\Command;

final class ViolationsProcess extends Command
{
    protected $signature = 'violations:process';

    protected $description = 'Uphold violations whose appeal window ended and refresh active points (idempotent)';

    public function handle(ProcessViolations $process): int
    {
        $result = $process->handle();
        $this->info("Upheld {$result['upheld']} violation(s), refreshed {$result['refreshed']} store total(s).");

        return self::SUCCESS;
    }
}
