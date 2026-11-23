<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts\Pages;

use App\Filament\Resources\Payouts\WithdrawalResource;
use Filament\Resources\Pages\ListRecords;

final class ListWithdrawals extends ListRecords
{
    protected static string $resource = WithdrawalResource::class;
}
