<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Platforms\Pages;

use App\Filament\Resources\Catalog\Platforms\PlatformResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPlatforms extends ListRecords
{
    protected static string $resource = PlatformResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
