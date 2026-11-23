<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\GameCategories\Pages;

use App\Filament\Resources\Catalog\GameCategories\GameCategoryResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateGameCategory extends CreateRecord
{
    protected static string $resource = GameCategoryResource::class;
}
