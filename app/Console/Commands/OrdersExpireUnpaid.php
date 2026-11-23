<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Orders\Actions\ExpireUnpaidOrders;
use Illuminate\Console\Command;

final class OrdersExpireUnpaid extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Cancel orders whose payment window passed, releasing stock and credits (idempotent)';

    public function handle(ExpireUnpaidOrders $expire): int
    {
        $this->info("Cancelled {$expire->handle()} unpaid order(s).");

        return self::SUCCESS;
    }
}
