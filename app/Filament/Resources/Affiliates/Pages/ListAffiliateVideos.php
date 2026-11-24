<?php

declare(strict_types=1);

namespace App\Filament\Resources\Affiliates\Pages;

use App\Filament\Resources\Affiliates\AffiliateVideoResource;
use Filament\Resources\Pages\ListRecords;

final class ListAffiliateVideos extends ListRecords
{
    protected static string $resource = AffiliateVideoResource::class;
}
