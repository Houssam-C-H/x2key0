<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Orders\Actions\ReleaseExpiredReservations;
use Illuminate\Console\Command;

final class StorageReleaseExpiredReservations extends Command
{
    protected $signature = 'storage:release-expired-reservations';

    protected $description = 'Safety net: return storage items reserved past their deadline for orders no longer awaiting payment';

    public function handle(ReleaseExpiredReservations $release): int
    {
        $this->info("Released {$release->handle()} reservation(s).");

        return self::SUCCESS;
    }
}
