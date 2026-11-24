<?php

declare(strict_types=1);

namespace App\Filament\Resources\Affiliates\Pages;

use App\Filament\Resources\Affiliates\AffiliateResource;
use Filament\Resources\Pages\ListRecords;

final class ListAffiliates extends ListRecords
{
    protected static string $resource = AffiliateResource::class;
}
