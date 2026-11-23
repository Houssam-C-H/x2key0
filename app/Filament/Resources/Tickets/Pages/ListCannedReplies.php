<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\CannedReplyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCannedReplies extends ListRecords
{
    protected static string $resource = CannedReplyResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
