<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\PageResource;
use Filament\Resources\Pages\CreateRecord;

final class CreatePage extends CreateRecord
{
    protected static string $resource = PageResource::class;
}
