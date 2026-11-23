<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Payments\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Test payment methods served by the fake PSP (D2 until M6b), outside production only. Real methods and the
 * footer strip come with the real PSP drivers (M6b).
 */
final class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $methods = [
            ['fake_card', ['en' => 'Card (test)', 'fr' => 'Carte (test)', 'ar' => 'بطاقة (تجريبي)'], 0, 0],
            ['fake_wallet', ['en' => 'E-wallet (test)', 'fr' => 'Portefeuille électronique (test)', 'ar' => 'محفظة إلكترونية (تجريبي)'], 150, 10],
        ];

        foreach ($methods as $sort => [$code, $name, $feeBps, $feeFixed]) {
            PaymentMethod::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'provider' => 'fake',
                'countries' => null,
                'currencies' => ['USD'],
                'min_amount' => 50,
                'max_amount' => 1_000_000,
                'fee_bps' => $feeBps,
                'fee_fixed' => $feeFixed,
                'is_enabled' => true,
                'sort' => $sort,
            ]);
        }
    }
}
