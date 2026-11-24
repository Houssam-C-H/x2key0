<?php

declare(strict_types=1);

namespace App\Filament\Resources\Affiliates\Pages;

use App\Filament\Resources\Affiliates\CreativeAssetResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCreativeAsset extends CreateRecord
{
    protected static string $resource = CreativeAssetResource::class;
}
