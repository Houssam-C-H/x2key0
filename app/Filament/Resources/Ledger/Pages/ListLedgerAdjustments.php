<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ledger\Pages;

use App\Filament\Resources\Ledger\LedgerAdjustmentResource;
use Filament\Resources\Pages\ListRecords;

final class ListLedgerAdjustments extends ListRecords
{
    protected static string $resource = LedgerAdjustmentResource::class;
}
