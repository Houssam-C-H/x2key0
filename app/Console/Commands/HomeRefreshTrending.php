<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Content\Actions\RefreshTrending;
use Illuminate\Console\Command;

/** `home:refresh-trending` (hourly, spec Part 23): trend and hot scores from the last 7 days of orders and views. */
final class HomeRefreshTrending extends Command
{
    protected $signature = 'home:refresh-trending';

    protected $description = 'Recompute trending game categories and hot games';

    public function handle(RefreshTrending $trending): int
    {
        $r = $trending->handle();
        $this->info("Scored {$r['categories']} game categories.");

        return self::SUCCESS;
    }
}
