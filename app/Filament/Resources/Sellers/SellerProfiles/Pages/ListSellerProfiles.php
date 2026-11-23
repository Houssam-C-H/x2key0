<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sellers\SellerProfiles\Pages;

use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use Filament\Resources\Pages\ListRecords;

final class ListSellerProfiles extends ListRecords
{
    protected static string $resource = SellerProfileResource::class;
}
