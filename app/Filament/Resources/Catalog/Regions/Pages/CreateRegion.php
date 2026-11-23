<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Regions\Pages;

use App\Filament\Resources\Catalog\Regions\RegionResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateRegion extends CreateRecord
{
    protected static string $resource = RegionResource::class;
}
