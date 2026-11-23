<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Regions\Pages;

use App\Filament\Resources\Catalog\Regions\RegionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListRegions extends ListRecords
{
    protected static string $resource = RegionResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
