<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Games\Pages;

use App\Filament\Resources\Catalog\Games\GameResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditGame extends EditRecord
{
    protected static string $resource = GameResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
