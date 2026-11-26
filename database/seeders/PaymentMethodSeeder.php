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
        // Stripe Checkout (M6b): offered only when STRIPE_SECRET is configured (StripePaymentProvider::supports).
        PaymentMethod::query()->updateOrCreate(['code' => 'stripe_card'], [
            'name' => ['en' => 'Card (Visa, Mastercard…) · Stripe', 'fr' => 'Carte (Visa, Mastercard…) · Stripe', 'ar' => 'بطاقة (فيزا، ماستركارد…) · Stripe'],
            'provider' => 'stripe',
            'countries' => null,
            'currencies' => ['USD'],
            'min_amount' => 50,
            'max_amount' => 1_000_000,
            'fee_bps' => 0,
            'fee_fixed' => 0,
            'is_enabled' => true,
            'sort' => 0,
        ]);

        // NOWPayments crypto (ADR-041): offered only when its API key and IPN secret are configured. The buyer picks
        // the coin on the NOWPayments page; small amounts are refused there, hence the higher minimum.
        PaymentMethod::query()->updateOrCreate(['code' => 'nowpayments_crypto'], [
            'name' => ['en' => 'Crypto (BTC, ETH, USDT…) · NOWPayments', 'fr' => 'Crypto (BTC, ETH, USDT…) · NOWPayments', 'ar' => 'عملات رقمية (BTC، ETH، USDT…) · NOWPayments'],
            'provider' => 'nowpayments',
            'countries' => null,
            'currencies' => ['USD'],
            'min_amount' => 500,
            'max_amount' => 1_000_000,
            'fee_bps' => 0,
            'fee_fixed' => 0,
            'is_enabled' => true,
            'sort' => 1,
        ]);

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
                'sort' => $sort + 1,
            ]);
        }
    }
}
