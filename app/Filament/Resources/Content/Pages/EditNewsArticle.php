<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\NewsArticleResource;
use Filament\Resources\Pages\EditRecord;

final class EditNewsArticle extends EditRecord
{
    protected static string $resource = NewsArticleResource::class;
}
