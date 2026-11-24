<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reviews\Pages;

use App\Filament\Resources\Reviews\OrderCommentResource;
use Filament\Resources\Pages\ListRecords;

final class ListOrderComments extends ListRecords
{
    protected static string $resource = OrderCommentResource::class;
}
