<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\GameCategories\Pages;

use App\Filament\Resources\Catalog\GameCategories\GameCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditGameCategory extends EditRecord
{
    protected static string $resource = GameCategoryResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
