<?php

declare(strict_types=1);

namespace App\Filament\Resources\Marketing\Pages;

use App\Domains\Coupons\Models\Coupon;
use App\Filament\Resources\Marketing\CouponResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateCoupon extends CreateRecord
{
    protected static string $resource = CouponResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $coupon = new Coupon;
        $coupon->forceFill(CouponResource::normalise($data))->save();

        return $coupon;
    }
}
