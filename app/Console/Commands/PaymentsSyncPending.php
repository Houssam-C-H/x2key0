<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Payments\Actions\SyncPendingPayments;
use Illuminate\Console\Command;

final class PaymentsSyncPending extends Command
{
    protected $signature = 'payments:sync-pending';

    protected $description = 'Ask PSPs about payments still open, in case a webhook was lost (idempotent)';

    public function handle(SyncPendingPayments $sync): int
    {
        $this->info("Synced {$sync->handle()} payment(s).");

        return self::SUCCESS;
    }
}
