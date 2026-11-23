<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Regions\Pages;

use App\Filament\Resources\Catalog\Regions\RegionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditRegion extends EditRecord
{
    protected static string $resource = RegionResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
