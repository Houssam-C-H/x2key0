<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ledger\Pages;

use App\Filament\Resources\Ledger\LedgerJournalResource;
use Filament\Resources\Pages\ListRecords;

final class ListLedgerJournals extends ListRecords
{
    protected static string $resource = LedgerJournalResource::class;
}
