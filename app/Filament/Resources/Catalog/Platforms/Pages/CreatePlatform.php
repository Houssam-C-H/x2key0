<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Platforms\Pages;

use App\Filament\Resources\Catalog\Platforms\PlatformResource;
use Filament\Resources\Pages\CreateRecord;

final class CreatePlatform extends CreateRecord
{
    protected static string $resource = PlatformResource::class;
}
