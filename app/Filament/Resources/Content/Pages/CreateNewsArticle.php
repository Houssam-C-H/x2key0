<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\NewsArticleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateNewsArticle extends CreateRecord
{
    protected static string $resource = NewsArticleResource::class;
}
