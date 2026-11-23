<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\GameCategories\Pages;

use App\Filament\Resources\Catalog\GameCategories\GameCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListGameCategories extends ListRecords
{
    protected static string $resource = GameCategoryResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
