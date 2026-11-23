<?php

declare(strict_types=1);

use App\Domains\Checkout\Support\QuoteCalculator;
use App\Domains\Offers\Models\Offer;
use App\Domains\Payments\Models\PaymentMethod;
use App\Domains\Sellers\Models\SellerProfile;
use Database\Seeders\PaymentMethodSeeder;

beforeEach(function (): void {
    (new PaymentMethodSeeder)->run();
    $this->calc = app(QuoteCalculator::class);
    $this->offer = fn (array $attrs = []) => Offer::factory()->create(['unit_price_amount' => 1000, ...$attrs]);
});

it('charges 3 % with a $0.30 minimum, half-up, on the whole order', function (int $unit, int $qty, int $fee): void {
    $q = $this->calc->quote(($this->offer)(['unit_price_amount' => $unit]), $qty, null, 0);

    expect($q->subtotal)->toBe($unit * $qty)
        ->and($q->buyerFee)->toBe($fee)
        ->and($q->total)->toBe($unit * $qty + $fee)
        ->and($q->pspDue)->toBe($q->total);
})->with([
    '$1.00 → minimum $0.30' => [100, 1, 30],
    '$10.00 → $0.30' => [1000, 1, 30],
    '$10.50 → 31.5¢ rounds half-up to 32¢' => [1050, 1, 32],
    '$33.33 × 3 → 299.97¢ → $3.00' => [3333, 3, 300],
]);

it('applies quantity tiers, the category add-on fee and the seller level commission', function (): void {
    $offer = ($this->offer)(['quantity_tiers' => [['min_qty' => 10, 'unit_price_amount' => 900], ['min_qty' => 50, 'unit_price_amount' => 800]]]);
    $offer->gameCategory->forceFill(['policy_override' => ['addon_fee_bps' => 1000]])->save();

    $q = $this->calc->quote($offer->refresh(), 10, null, 0);

    expect($q->unitPrice)->toBe(900)
        ->and($q->subtotal)->toBe(9000)
        ->and($q->addonFee)->toBe(900)
        ->and($q->buyerFee)->toBe(270)
        ->and($q->total)->toBe(10170)
        ->and($q->commissionBps)->toBe(800) // entry level
        ->and($q->commission)->toBe(720)
        ->and($q->sellerNet)->toBe(8280);

    $gold = Offer::factory()->create(['seller_profile_id' => SellerProfile::factory()->level('gold'), 'unit_price_amount' => 1000]);
    expect($this->calc->quote($gold, 1, null, 0)->commission)->toBe(70);
});

it('takes credits first and charges the method surcharge on the card part only', function (): void {
    $offer = ($this->offer)(['unit_price_amount' => 2000]); // 2000 + fee 60 = 2060
    $wallet = PaymentMethod::query()->where('code', 'fake_wallet')->sole(); // 1.5 % + 10¢

    $none = $this->calc->quote($offer, 1, $wallet, 0);
    expect($none->methodFee)->toBe(41) // 30.9 → 31 + 10
        ->and($none->total)->toBe(2101)
        ->and($none->pspDue)->toBe(2101);

    $mixed = $this->calc->quote($offer, 1, $wallet, 1000);
    expect($mixed->credits)->toBe(1000)
        ->and($mixed->methodFee)->toBe(26) // 1.5 % of 1060 = 15.9 → 16 + 10
        ->and($mixed->pspDue)->toBe(1086)
        ->and($mixed->total)->toBe(2086);

    $all = $this->calc->quote($offer, 1, $wallet, 5000);
    expect($all->credits)->toBe(2060)->and($all->pspDue)->toBe(0)->and($all->methodFee)->toBe(0);
});
