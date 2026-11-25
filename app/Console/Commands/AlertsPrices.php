<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Engagement\Actions\PriceAlerts;
use Illuminate\Console\Command;

/** `alerts:prices` (every ten minutes): fire price alerts whose target is reached, re-arm the others. */
final class AlertsPrices extends Command
{
    protected $signature = 'alerts:prices';

    protected $description = 'Notify buyers whose price alert target is reached (idempotent)';

    public function handle(PriceAlerts $alerts): int
    {
        $this->info('Price alerts fired: '.$alerts->check());

        return self::SUCCESS;
    }
}
