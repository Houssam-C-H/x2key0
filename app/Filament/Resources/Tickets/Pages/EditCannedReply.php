<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\CannedReplyResource;
use Filament\Resources\Pages\EditRecord;

final class EditCannedReply extends EditRecord
{
    protected static string $resource = CannedReplyResource::class;
}
