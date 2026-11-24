<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\SeoLinkRowResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSeoLinkRow extends CreateRecord
{
    protected static string $resource = SeoLinkRowResource::class;
}
