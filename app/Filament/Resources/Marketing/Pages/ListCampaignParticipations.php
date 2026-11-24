<?php

declare(strict_types=1);

namespace App\Filament\Resources\Marketing\Pages;

use App\Filament\Resources\Marketing\CampaignParticipationResource;
use Filament\Resources\Pages\ListRecords;

final class ListCampaignParticipations extends ListRecords
{
    protected static string $resource = CampaignParticipationResource::class;
}
