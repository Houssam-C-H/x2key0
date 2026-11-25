<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Money\DisplayRates;
use Illuminate\Console\Command;
use Throwable;

/** `fx:refresh` (daily): display exchange rates for the buyer's currency (ADR-036). Charges stay USD. */
final class FxRefresh extends Command
{
    protected $signature = 'fx:refresh';

    protected $description = 'Refresh display exchange rates (approximate prices only; charges stay USD)';

    public function handle(DisplayRates $rates): int
    {
        try {
            $this->info('Rates stored: '.$rates->refresh());
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
