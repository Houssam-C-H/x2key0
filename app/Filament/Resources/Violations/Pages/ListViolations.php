<?php

declare(strict_types=1);

namespace App\Filament\Resources\Violations\Pages;

use App\Filament\Resources\Violations\ViolationResource;
use Filament\Resources\Pages\ListRecords;

final class ListViolations extends ListRecords
{
    protected static string $resource = ViolationResource::class;
}
