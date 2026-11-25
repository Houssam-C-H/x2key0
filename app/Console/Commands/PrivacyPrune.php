<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Actions\PruneRetention;
use Illuminate\Console\Command;

/** `privacy:prune` (daily, spec Part 23 "retention per table"). */
final class PrivacyPrune extends Command
{
    protected $signature = 'privacy:prune';

    protected $description = 'Delete data past its retention period (x2key.retention_days)';

    public function handle(PruneRetention $prune): int
    {
        foreach ($prune->handle() as $rule => $count) {
            $this->line("{$rule}: {$count}");
        }

        return self::SUCCESS;
    }
}
