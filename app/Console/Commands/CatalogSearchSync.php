<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Catalog\Search\CatalogSearch;
use App\Domains\Catalog\Search\MeilisearchCatalogSearch;
use Illuminate\Console\Command;

final class CatalogSearchSync extends Command
{
    protected $signature = 'catalog:search-sync';

    protected $description = 'Configure the Meilisearch products index and rebuild it from the database';

    public function handle(CatalogSearch $search): int
    {
        if (! $search instanceof MeilisearchCatalogSearch) {
            $this->info('Search driver is "database": nothing to sync.');

            return self::SUCCESS;
        }

        $count = $search->waitForTasks()->reindex();
        $this->info("Indexed {$count} products.");

        return self::SUCCESS;
    }
}
