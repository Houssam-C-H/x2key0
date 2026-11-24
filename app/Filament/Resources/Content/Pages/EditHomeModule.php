<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\HomeModuleResource;
use Filament\Resources\Pages\EditRecord;

final class EditHomeModule extends EditRecord
{
    protected static string $resource = HomeModuleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return HomeModuleResource::normalise($data);
    }
}
