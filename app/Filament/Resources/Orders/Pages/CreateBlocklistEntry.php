<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\BlocklistResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateBlocklistEntry extends CreateRecord
{
    protected static string $resource = BlocklistResource::class;
}
