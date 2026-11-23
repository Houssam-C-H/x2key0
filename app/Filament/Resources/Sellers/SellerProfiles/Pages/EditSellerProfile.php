<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sellers\SellerProfiles\Pages;

use App\Filament\Resources\Sellers\SellerProfiles\SellerProfileResource;
use Filament\Resources\Pages\EditRecord;

/** Changes are audited by SellerProfile's Auditable trait. Status is changed by the list actions only. */
final class EditSellerProfile extends EditRecord
{
    protected static string $resource = SellerProfileResource::class;
}
