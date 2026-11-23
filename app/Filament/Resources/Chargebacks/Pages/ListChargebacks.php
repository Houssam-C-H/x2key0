<?php

declare(strict_types=1);

namespace App\Filament\Resources\Chargebacks\Pages;

use App\Filament\Resources\Chargebacks\ChargebackResource;
use Filament\Resources\Pages\ListRecords;

final class ListChargebacks extends ListRecords
{
    protected static string $resource = ChargebackResource::class;
}
