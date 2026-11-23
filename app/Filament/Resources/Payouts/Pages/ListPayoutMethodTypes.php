<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts\Pages;

use App\Filament\Resources\Payouts\PayoutMethodTypeResource;
use Filament\Resources\Pages\ListRecords;

final class ListPayoutMethodTypes extends ListRecords
{
    protected static string $resource = PayoutMethodTypeResource::class;
}
