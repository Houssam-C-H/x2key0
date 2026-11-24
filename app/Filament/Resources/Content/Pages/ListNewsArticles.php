<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\NewsArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListNewsArticles extends ListRecords
{
    protected static string $resource = NewsArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
