<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\HomeModuleResource;
use Filament\Resources\Pages\ListRecords;

final class ListHomeModules extends ListRecords
{
    protected static string $resource = HomeModuleResource::class;
}
