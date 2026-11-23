<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Games\Pages;

use App\Filament\Resources\Catalog\Games\GameResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListGames extends ListRecords
{
    protected static string $resource = GameResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
