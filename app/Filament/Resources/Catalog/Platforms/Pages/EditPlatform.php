<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Platforms\Pages;

use App\Filament\Resources\Catalog\Platforms\PlatformResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditPlatform extends EditRecord
{
    protected static string $resource = PlatformResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
