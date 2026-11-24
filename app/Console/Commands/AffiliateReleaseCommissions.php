<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Affiliate\Actions\AffiliateCommissions;
use Illuminate\Console\Command;

/** `affiliate:release-commissions` (hourly, spec Part 15): held commissions past their date become available. */
final class AffiliateReleaseCommissions extends Command
{
    protected $signature = 'affiliate:release-commissions';

    protected $description = 'Release held affiliate commissions (AFFILIATE_RELEASE)';

    public function handle(AffiliateCommissions $commissions): int
    {
        $this->info('Released '.$commissions->release().' commission(s).');

        return self::SUCCESS;
    }
}
