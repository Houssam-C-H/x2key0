<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Campaigns\Actions\SettleMarketingEmails;
use Illuminate\Console\Command;

/** `marketing:settle` (daily, spec 14.3): bills earlier marketing e-mail sends from the sellers' Available balance. */
final class MarketingSettle extends Command
{
    protected $signature = 'marketing:settle';

    protected $description = 'Bill unsettled marketing e-mail sends (MARKETING_FEE)';

    public function handle(SettleMarketingEmails $settle): int
    {
        $result = $settle->handle();
        $this->info("Settled {$result['settled']}, still unsettled {$result['unsettled']}.");

        return self::SUCCESS;
    }
}
