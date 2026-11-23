<?php

declare(strict_types=1);

namespace App\Domains\Payments\Data;

/** What a PSP needs besides the payment row: where to send the buyer back, and who pays. */
final readonly class PaymentContext
{
    public function __construct(
        public string $returnUrl,
        public string $buyerEmail,
        public ?string $buyerCountry,
        public ?string $ip,
        public string $description,
    ) {}
}
