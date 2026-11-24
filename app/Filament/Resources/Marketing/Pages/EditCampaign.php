<?php

declare(strict_types=1);

namespace App\Filament\Resources\Marketing\Pages;

use App\Filament\Resources\Marketing\CampaignResource;
use Filament\Resources\Pages\EditRecord;

final class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CampaignResource::normalise($data);
    }
}
