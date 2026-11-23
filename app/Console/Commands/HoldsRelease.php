<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Ledger\Actions\ReleaseHolds;
use Illuminate\Console\Command;

final class HoldsRelease extends Command
{
    protected $signature = 'holds:release';

    protected $description = 'Release seller earnings, insurance installments and reserves whose hold ended (idempotent)';

    public function handle(ReleaseHolds $release): int
    {
        $this->info("Released {$release->handle()} hold(s).");

        return self::SUCCESS;
    }
}
