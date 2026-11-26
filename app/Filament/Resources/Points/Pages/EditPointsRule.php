<?php

declare(strict_types=1);

namespace App\Filament\Resources\Points\Pages;

use App\Filament\Resources\Points\PointsRuleResource;
use Filament\Resources\Pages\EditRecord;

final class EditPointsRule extends EditRecord
{
    protected static string $resource = PointsRuleResource::class;
}
