<?php

declare(strict_types=1);

namespace App\Domains\Payments\Providers;

use App\Domains\Payments\Contracts\PaymentProvider;
use App\Domains\Payments\Data\PaymentContext;
use App\Domains\Payments\Data\PaymentIntentResult;
use App\Domains\Payments\Data\ProviderEvent;
use App\Domains\Payments\Data\ProviderPaymentState;
use App\Domains\Payments\Data\RefundResult;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\InvalidWebhook;
use App\Domains\Payments\Models\Payment;
use Brick\Money\Money;
use Illuminate\Http\Request;
use LogicException;
use SensitiveParameter;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe (spec 9.1 / M6b) through Stripe Checkout: the buyer pays on Stripe's hosted page (cards, 3-D Secure,
 * wallets Stripe enables for the account). The provider payment id is the Checkout Session id.
 *
 * - webhooks (`/webhooks/stripe`, Stripe-Signature verified with the endpoint secret): checkout.session.completed /
 *   async_payment_succeeded → captured when paid; async_payment_failed → failed; checkout.session.expired → cancelled;
 * - fetchPayment reads the session from the API (used by `payments:sync-pending` and right after the buyer returns);
 * - refunds go to the session's PaymentIntent with the journal key as Stripe idempotency key.
 *
 * Offered only when STRIPE_SECRET is set. Amounts are integer minor units, currencies lower-case for Stripe.
 */
final class StripePaymentProvider implements PaymentProvider
{
    public const string CODE = 'stripe';

    public function __construct(
        #[SensitiveParameter] private readonly string $secret,
        #[SensitiveParameter] private readonly string $webhookSecret,
        private readonly ?StripeClient $client = null,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function supports(string $methodCode, ?string $country, Money $amount): bool
    {
        return $this->secret !== '';
    }

    public function createPayment(Payment $payment, PaymentContext $context): PaymentIntentResult
    {
        $separator = str_contains($context->returnUrl, '?') ? '&' : '?';

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'client_reference_id' => $payment->public_id,
            'customer_email' => $context->buyerEmail,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($payment->currency),
                    'unit_amount' => $payment->amount,
                    'product_data' => ['name' => mb_substr($context->description, 0, 250)],
                ],
            ]],
            'payment_intent_data' => ['metadata' => ['payment' => $payment->public_id]],
            'metadata' => ['payment' => $payment->public_id],
            'success_url' => $context->returnUrl.$separator.'stripe=return',
            'cancel_url' => $context->returnUrl.$separator.'stripe=cancel',
            'expires_at' => now()->addMinutes(31)->getTimestamp(), // Stripe's minimum is 30 minutes
        ], ['idempotency_key' => "checkout:{$payment->public_id}"]);

        return new PaymentIntentResult($session->id, PaymentStatus::Pending, $session->url);
    }

    public function parseWebhook(Request $request): ProviderEvent
    {
        if ($this->webhookSecret === '') {
            throw new InvalidWebhook('Stripe webhook secret is not configured.');
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $this->webhookSecret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            throw new InvalidWebhook('Invalid signature.');
        }

        $object = $event->data->object;
        if (! $object instanceof Session) {
            throw new InvalidWebhook('Unsupported event object.');
        }

        $status = match ($event->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $object->payment_status === 'paid' ? PaymentStatus::Captured : PaymentStatus::Pending,
            'checkout.session.async_payment_failed' => PaymentStatus::Failed,
            'checkout.session.expired' => PaymentStatus::Cancelled,
            default => throw new InvalidWebhook('Unhandled event type.'),
        };

        return new ProviderEvent(
            eventId: $event->id,
            type: $event->type,
            providerPaymentId: $object->id,
            status: $status,
            amount: (int) $object->amount_total,
            currency: strtoupper((string) $object->currency),
            payload: ['type' => $event->type, 'session' => $object->id, 'payment_status' => $object->payment_status],
        );
    }

    public function fetchPayment(string $providerPaymentId): ProviderPaymentState
    {
        $session = $this->client()->checkout->sessions->retrieve($providerPaymentId);

        $status = match (true) {
            $session->payment_status === 'paid' => PaymentStatus::Captured,
            $session->status === 'expired' => PaymentStatus::Cancelled,
            default => PaymentStatus::Pending,
        };

        return new ProviderPaymentState($status, (int) $session->amount_total, strtoupper((string) $session->currency));
    }

    public function refund(Payment $payment, int $amount, string $idempotencyKey): RefundResult
    {
        $session = $this->client()->checkout->sessions->retrieve((string) $payment->provider_payment_id);
        $intent = is_string($session->payment_intent) ? $session->payment_intent : $session->payment_intent?->id;

        if (! is_string($intent)) {
            throw new LogicException("Stripe session {$session->id} has no PaymentIntent to refund.");
        }

        $refund = $this->client()->refunds->create(
            ['payment_intent' => $intent, 'amount' => $amount, 'metadata' => ['key' => $idempotencyKey]],
            ['idempotency_key' => $idempotencyKey],
        );

        return new RefundResult($refund->id, $refund->status === 'succeeded' ? 'succeeded' : 'processing');
    }

    private function client(): StripeClient
    {
        if ($this->secret === '') {
            throw new LogicException('Stripe is not configured (STRIPE_SECRET).');
        }

        return $this->client ?? new StripeClient($this->secret);
    }
}
