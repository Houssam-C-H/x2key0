<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Catalog\Actions\RecountCatalog;
use Illuminate\Console\Command;

final class CatalogRecount extends Command
{
    protected $signature = 'catalog:recount';

    protected $description = 'Recompute cached product/offer counts for games and game categories (mega menu, tabs, hubs)';

    public function handle(RecountCatalog $recount): int
    {
        $recount->handle();
        $this->info('Catalog counts refreshed.');

        return self::SUCCESS;
    }
}
