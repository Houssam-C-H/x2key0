<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\SeoLinkRowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSeoLinkRows extends ListRecords
{
    protected static string $resource = SeoLinkRowResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
