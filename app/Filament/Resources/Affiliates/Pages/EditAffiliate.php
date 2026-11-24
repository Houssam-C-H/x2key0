<?php

declare(strict_types=1);

namespace App\Filament\Resources\Affiliates\Pages;

use App\Filament\Resources\Affiliates\AffiliateResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditAffiliate extends EditRecord
{
    protected static string $resource = AffiliateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return AffiliateResource::save($record, $data);
    }
}
