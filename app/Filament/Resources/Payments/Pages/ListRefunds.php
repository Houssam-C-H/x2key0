<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\RefundResource;
use Filament\Resources\Pages\ListRecords;

final class ListRefunds extends ListRecords
{
    protected static string $resource = RefundResource::class;
}
