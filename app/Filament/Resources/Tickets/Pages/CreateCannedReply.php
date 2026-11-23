<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\CannedReplyResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCannedReply extends CreateRecord
{
    protected static string $resource = CannedReplyResource::class;
}
