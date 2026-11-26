<?php

declare(strict_types=1);

namespace App\Filament\Resources\Points\Pages;

use App\Filament\Resources\Points\PointsRuleResource;
use Filament\Resources\Pages\ListRecords;

final class ListPointsRules extends ListRecords
{
    protected static string $resource = PointsRuleResource::class;
}
