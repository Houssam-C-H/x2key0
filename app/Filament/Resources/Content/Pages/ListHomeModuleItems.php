<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\HomeModuleItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListHomeModuleItems extends ListRecords
{
    protected static string $resource = HomeModuleItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
