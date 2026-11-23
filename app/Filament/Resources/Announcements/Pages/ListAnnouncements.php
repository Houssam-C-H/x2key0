<?php

declare(strict_types=1);

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    /**
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
