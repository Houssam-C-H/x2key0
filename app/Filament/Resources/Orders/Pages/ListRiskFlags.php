<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\RiskFlagResource;
use Filament\Resources\Pages\ListRecords;

final class ListRiskFlags extends ListRecords
{
    protected static string $resource = RiskFlagResource::class;
}
