<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentEventResource;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentEvents extends ListRecords
{
    protected static string $resource = PaymentEventResource::class;
}
