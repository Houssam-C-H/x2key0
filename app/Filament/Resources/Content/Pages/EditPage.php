<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\PageResource;
use Filament\Resources\Pages\EditRecord;

final class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;
}
