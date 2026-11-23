<?php

declare(strict_types=1);

namespace App\Domains\Payments\Data;

/** PSP answer to a refund request: its id and `succeeded` | `processing` | `failed`. */
final readonly class RefundResult
{
    public function __construct(
        public string $providerRefundId,
        public string $status,
    ) {}
}
