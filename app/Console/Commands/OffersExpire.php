<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Offers\Actions\ExpireOffers;
use Illuminate\Console\Command;

final class OffersExpire extends Command
{
    protected $signature = 'offers:expire';

    protected $description = 'Move listed offers past their expiration date to "expired" (idempotent)';

    public function handle(ExpireOffers $expire): int
    {
        $this->info("Expired {$expire->handle()} offer(s).");

        return self::SUCCESS;
    }
}
