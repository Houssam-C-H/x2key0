<?php

declare(strict_types=1);

namespace App\Filament\Resources\Marketing\Pages;

use App\Filament\Resources\Marketing\CouponResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditCoupon extends EditRecord
{
    protected static string $resource = CouponResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill(CouponResource::normalise($data))->save();

        return $record;
    }
}
