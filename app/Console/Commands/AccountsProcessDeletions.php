<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Actions\DeleteAccount;
use Illuminate\Console\Command;

/** `accounts:process-deletions` (daily, spec Part 23): completes deletions whose cool-off has ended. */
final class AccountsProcessDeletions extends Command
{
    protected $signature = 'accounts:process-deletions';

    protected $description = 'Anonymise accounts whose deletion cool-off has ended';

    public function handle(DeleteAccount $delete): int
    {
        $r = $delete->processDue();
        $this->info("Deleted {$r['completed']}, blocked {$r['blocked']}.");

        return self::SUCCESS;
    }
}
