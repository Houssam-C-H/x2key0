<?php

declare(strict_types=1);

namespace App\Filament\Resources\Affiliates\Pages;

use App\Filament\Resources\Affiliates\CreativeAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCreativeAssets extends ListRecords
{
    protected static string $resource = CreativeAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
