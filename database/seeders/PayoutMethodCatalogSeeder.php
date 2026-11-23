<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Payouts\Models\PayoutMethodType;
use Illuminate\Database\Seeder;

/**
 * Payout methods (spec 1.8 / 5.16). Fees are starting values for finance to adjust; payouts settle in USD until
 * FX conversion exists.
 */
final class PayoutMethodCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['paypal', 'PayPal', ['email'], 299, 99, 1000],
            ['payoneer', 'Payoneer', ['email'], 299, 299, 2000],
            ['usdt_trc20', 'USDT (TRC20)', ['wallet_address'], 100, 300, 2000],
            ['bank_transfer', 'Bank transfer (SWIFT/SEPA)', ['account_holder', 'iban', 'swift'], 50, 1500, 5000],
        ];

        foreach ($methods as $sort => [$code, $name, $fields, $bps, $fixed, $min]) {
            PayoutMethodType::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'currencies' => ['USD'],
                'fields' => $fields,
                'fee_bps' => $bps,
                'fee_fixed_amount' => $fixed,
                'min_amount' => $min,
                'sort' => $sort,
                'is_enabled' => true,
            ]);
        }
    }
}
