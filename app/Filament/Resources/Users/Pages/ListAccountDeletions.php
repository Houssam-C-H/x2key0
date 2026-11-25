<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\AccountDeletionResource;
use Filament\Resources\Pages\ListRecords;

final class ListAccountDeletions extends ListRecords
{
    protected static string $resource = AccountDeletionResource::class;
}
