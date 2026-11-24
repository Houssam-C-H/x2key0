<?php

declare(strict_types=1);

namespace App\Filament\Resources\Marketing\Pages;

use App\Filament\Resources\Marketing\CampaignResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return CampaignResource::normalise($data);
    }
}
