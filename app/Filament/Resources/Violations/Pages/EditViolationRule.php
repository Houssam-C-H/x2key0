<?php

declare(strict_types=1);

namespace App\Filament\Resources\Violations\Pages;

use App\Filament\Resources\Violations\ViolationRuleResource;
use Filament\Resources\Pages\EditRecord;

final class EditViolationRule extends EditRecord
{
    protected static string $resource = ViolationRuleResource::class;
}
