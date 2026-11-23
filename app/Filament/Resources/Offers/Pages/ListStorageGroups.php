<?php

declare(strict_types=1);

namespace App\Filament\Resources\Offers\Pages;

use App\Filament\Resources\Offers\StorageGroupResource;
use Filament\Resources\Pages\ListRecords;

final class ListStorageGroups extends ListRecords
{
    protected static string $resource = StorageGroupResource::class;
}
