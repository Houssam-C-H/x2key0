<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentMethodResource;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentMethods extends ListRecords
{
    protected static string $resource = PaymentMethodResource::class;
}
