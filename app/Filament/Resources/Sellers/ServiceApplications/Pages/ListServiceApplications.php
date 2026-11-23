<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sellers\ServiceApplications\Pages;

use App\Filament\Resources\Sellers\ServiceApplications\ServiceApplicationResource;
use Filament\Resources\Pages\ListRecords;

final class ListServiceApplications extends ListRecords
{
    protected static string $resource = ServiceApplicationResource::class;
}
