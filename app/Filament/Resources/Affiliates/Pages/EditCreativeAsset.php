<?php

declare(strict_types=1);

namespace App\Filament\Resources\Affiliates\Pages;

use App\Filament\Resources\Affiliates\CreativeAssetResource;
use Filament\Resources\Pages\EditRecord;

final class EditCreativeAsset extends EditRecord
{
    protected static string $resource = CreativeAssetResource::class;
}
