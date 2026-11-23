<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sellers\SellerLevels\Pages;

use App\Filament\Resources\Sellers\SellerLevels\SellerLevelResource;
use Filament\Resources\Pages\EditRecord;

final class EditSellerLevel extends EditRecord
{
    protected static string $resource = SellerLevelResource::class;
}
