<?php

declare(strict_types=1);

namespace App\Filament\Resources\Points\Pages;

use App\Filament\Resources\Points\PointsAdjustmentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListPointsAdjustments extends ListRecords
{
    protected static string $resource = PointsAdjustmentResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [PointsAdjustmentResource::requestAction()];
    }
}
