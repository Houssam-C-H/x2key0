<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts\Pages;

use App\Filament\Resources\Payouts\PayoutMethodTypeResource;
use Filament\Resources\Pages\EditRecord;

final class EditPayoutMethodType extends EditRecord
{
    protected static string $resource = PayoutMethodTypeResource::class;
}
