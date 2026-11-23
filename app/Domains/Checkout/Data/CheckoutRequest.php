<?php

declare(strict_types=1);

namespace App\Domains\Checkout\Data;

use SensitiveParameter;

/** A validated checkout submission (spec Part 20 `POST /checkout`). */
final readonly class CheckoutRequest
{
    /**
     * @param  array<string, string|int|float|null>  $buyerInputs
     */
    public function __construct(
        public string $offerPublicId,
        public int $quantity,
        #[SensitiveParameter] public array $buyerInputs,
        public ?string $methodCode,
        public bool $useCredits,
        public string $idempotencyKey,
        public ?string $ip,
        public ?string $country,
    ) {}
}
