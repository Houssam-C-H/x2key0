<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\HelpArticleResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateHelpArticle extends CreateRecord
{
    protected static string $resource = HelpArticleResource::class;
}
