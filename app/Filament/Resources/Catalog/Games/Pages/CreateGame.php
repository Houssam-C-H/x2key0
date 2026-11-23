<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Games\Pages;

use App\Filament\Resources\Catalog\Games\GameResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateGame extends CreateRecord
{
    protected static string $resource = GameResource::class;
}
