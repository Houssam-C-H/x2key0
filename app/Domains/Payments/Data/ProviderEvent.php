<?php

declare(strict_types=1);

namespace App\Domains\Payments\Data;

use App\Domains\Payments\Enums\PaymentStatus;

/**
 * A verified provider notification, normalised. `amount`/`currency` are what the PSP says it processed;
 * ApplyPaymentEvent refuses to move state when they differ from the payment (spec 9.3 #6).
 *
 * @phpstan-type Card array{bin: string|null, last4: string|null, country: string|null}
 */
final readonly class ProviderEvent
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array{bin: string|null, last4: string|null, country: string|null}|null  $card
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public string $providerPaymentId,
        public PaymentStatus $status,
        public int $amount,
        public string $currency,
        public array $payload,
        public ?array $card = null,
        public bool $threeDs = false,
        public ?string $failureReason = null,
    ) {}
}
