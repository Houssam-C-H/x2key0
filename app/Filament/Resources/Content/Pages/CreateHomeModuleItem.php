<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\HomeModuleItemResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateHomeModuleItem extends CreateRecord
{
    protected static string $resource = HomeModuleItemResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return HomeModuleItemResource::normalise($data);
    }
}
