<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Sellers\Actions\RecomputeLevels;
use Illuminate\Console\Command;

/** `levels:recompute` (daily, spec 3.3): seller stats and levels, buyer levels. Locked seller levels are kept. */
final class LevelsRecompute extends Command
{
    protected $signature = 'levels:recompute';

    protected $description = 'Recompute seller stats and levels, and buyer levels';

    public function handle(RecomputeLevels $levels): int
    {
        $r = $levels->all();
        $this->info("Sellers {$r['sellers']} ({$r['changed']} level changes), buyer levels changed {$r['users']}.");

        return self::SUCCESS;
    }
}
