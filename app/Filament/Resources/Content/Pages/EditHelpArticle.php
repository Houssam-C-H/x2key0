<?php

declare(strict_types=1);

namespace App\Filament\Resources\Content\Pages;

use App\Filament\Resources\Content\HelpArticleResource;
use Filament\Resources\Pages\EditRecord;

final class EditHelpArticle extends EditRecord
{
    protected static string $resource = HelpArticleResource::class;
}
