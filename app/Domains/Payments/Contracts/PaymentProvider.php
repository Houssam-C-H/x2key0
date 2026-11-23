<?php

declare(strict_types=1);

namespace App\Domains\Payments\Contracts;

use App\Domains\Payments\Data\PaymentContext;
use App\Domains\Payments\Data\PaymentIntentResult;
use App\Domains\Payments\Data\ProviderEvent;
use App\Domains\Payments\Data\ProviderPaymentState;
use App\Domains\Payments\Data\RefundResult;
use App\Domains\Payments\Exceptions\InvalidWebhook;
use App\Domains\Payments\Models\Payment;
use Brick\Money\Money;
use Illuminate\Http\Request;

/**
 * A PSP driver (spec 9.1). Drivers never change order or payment state themselves: webhooks are stored as
 * payment_events and applied by ApplyPaymentEvent; refunds are requested by RefundOrder.
 */
interface PaymentProvider
{
    public function code(): string;

    public function supports(string $methodCode, ?string $country, Money $amount): bool;

    public function createPayment(Payment $payment, PaymentContext $context): PaymentIntentResult;

    /** @throws InvalidWebhook when the signature or the body is not valid */
    public function parseWebhook(Request $request): ProviderEvent;

    public function fetchPayment(string $providerPaymentId): ProviderPaymentState;

    /** Returns (part of) a captured payment to the buyer. The same key never refunds twice. */
    public function refund(Payment $payment, int $amount, string $idempotencyKey): RefundResult;
}
