<?php

declare(strict_types=1);

namespace App\Domains\Payments\Data;

use App\Domains\Payments\Enums\PaymentStatus;

/** The PSP's current view of a payment, for `payments:sync-pending`. */
final readonly class ProviderPaymentState
{
    public function __construct(
        public PaymentStatus $status,
        public int $amount,
        public string $currency,
    ) {}
}
