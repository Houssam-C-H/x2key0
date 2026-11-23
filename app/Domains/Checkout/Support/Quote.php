<?php

declare(strict_types=1);

namespace App\Domains\Checkout\Support;

/**
 * The priced breakdown of one checkout (spec 1.6 price lines, 7.2). All amounts are integer minor units of
 * `currency`; `total = subtotal − discounts + buyerFee + addonFee + methodFee + tax`, and
 * `total = credits + pspDue`.
 */
final readonly class Quote
{
    public function __construct(
        public string $currency,
        public int $quantity,
        public int $unitPrice,
        public int $subtotal,
        public int $discountPlatform,
        public int $discountSeller,
        public int $buyerFee,
        public int $addonFee,
        public int $methodFee,
        public int $tax,
        public int $total,
        public int $credits,
        public int $pspDue,
        public int $commissionBps,
        public int $commission,
        public int $sellerNet,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice,
            'subtotal' => $this->subtotal,
            'discountPlatform' => $this->discountPlatform,
            'discountSeller' => $this->discountSeller,
            'buyerFee' => $this->buyerFee,
            'addonFee' => $this->addonFee,
            'methodFee' => $this->methodFee,
            'tax' => $this->tax,
            'total' => $this->total,
            'credits' => $this->credits,
            'pspDue' => $this->pspDue,
        ];
    }
}
