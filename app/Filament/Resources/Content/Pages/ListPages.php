<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\PageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
