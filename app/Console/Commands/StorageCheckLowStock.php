<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Storage\Actions\RefreshStorageStock;
use App\Domains\Storage\Models\StorageGroup;
use Illuminate\Console\Command;

final class StorageCheckLowStock extends Command
{
    protected $signature = 'storage:check-low-stock';

    protected $description = 'Recount storage stock, toggle out-of-stock offers and open/resolve low-stock alerts (idempotent)';

    public function handle(RefreshStorageStock $refresh): int
    {
        $count = 0;

        StorageGroup::query()->chunkById(200, function ($groups) use ($refresh, &$count): void {
            foreach ($groups as $group) {
                $refresh->handle($group);
                $count++;
            }
        });

        $this->info("Checked {$count} storage group(s).");

        return self::SUCCESS;
    }
}
