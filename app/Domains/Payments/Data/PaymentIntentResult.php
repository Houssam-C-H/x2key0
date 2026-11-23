<?php

declare(strict_types=1);

namespace App\Domains\Payments\Data;

use App\Domains\Payments\Enums\PaymentStatus;

/** Result of creating a payment at the PSP: its id, first status, and the page the buyer must visit. */
final readonly class PaymentIntentResult
{
    public function __construct(
        public string $providerPaymentId,
        public PaymentStatus $status,
        public ?string $redirectUrl,
    ) {}
}
