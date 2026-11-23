<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\CategoryTypes\Pages;

use App\Filament\Resources\Catalog\CategoryTypes\CategoryTypeResource;
use Filament\Resources\Pages\EditRecord;

final class EditCategoryType extends EditRecord
{
    protected static string $resource = CategoryTypeResource::class;
}
