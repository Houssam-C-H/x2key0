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
use App\Domains\Risk\Models\RiskFlag;
use Brick\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use JsonException;
use LogicException;
use RuntimeException;
use SensitiveParameter;

/**
 * NOWPayments crypto checkout (ADR-041), same shape as Stripe Checkout:
 * - createPayment opens a hosted invoice (price in USD, the buyer picks the coin there); the invoice id is the
 *   provider payment id and the buyer is redirected to `invoice_url`;
 * - IPN webhooks (`/webhooks/nowpayments`): `x-nowpayments-sig` = HMAC-SHA512 of the JSON body with keys sorted,
 *   keyed with the IPN secret. `confirmed` / `finished` → captured; `failed` → failed; `expired` → cancelled; every
 *   other status (waiting, confirming, sending, partially_paid, refunded) is recorded as pending;
 * - the event id is "{payment_id}:{status}", so a repeated notification is stored once;
 * - there is no API-key lookup of an invoice's payment, so fetchPayment reports pending and the IPN is the source of
 *   truth (`payments:sync-pending` leaves these alone);
 * - NOWPayments has no refund API for payments: a refund is recorded as `processing` and a `manual_crypto_refund`
 *   risk flag asks finance to send it back by hand.
 *
 * Offered only when both NOWPAYMENTS_API_KEY and NOWPAYMENTS_IPN_SECRET are set.
 */
final class NowPaymentsProvider implements PaymentProvider
{
    public const string CODE = 'nowpayments';

    private const array CAPTURED = ['confirmed', 'finished'];

    public function __construct(
        #[SensitiveParameter] private readonly string $apiKey,
        #[SensitiveParameter] private readonly string $ipnSecret,
        private readonly bool $sandbox = false,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function supports(string $methodCode, ?string $country, Money $amount): bool
    {
        return $this->apiKey !== '' && $this->ipnSecret !== '';
    }

    public function createPayment(Payment $payment, PaymentContext $context): PaymentIntentResult
    {
        if (! $this->supports('', null, Money::ofMinor($payment->amount, $payment->currency))) {
            throw new LogicException('NOWPayments is not configured (NOWPAYMENTS_API_KEY / NOWPAYMENTS_IPN_SECRET).');
        }

        $separator = str_contains($context->returnUrl, '?') ? '&' : '?';
        $response = Http::withHeaders(['x-api-key' => $this->apiKey])
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl().'/invoice', [
                'price_amount' => (float) self::decimal($payment->amount),
                'price_currency' => strtolower($payment->currency),
                'order_id' => $payment->public_id,
                'order_description' => mb_substr($context->description, 0, 250),
                'ipn_callback_url' => route('webhooks.payments', ['provider' => self::CODE]),
                'success_url' => $context->returnUrl.$separator.'crypto=return',
                'cancel_url' => $context->returnUrl.$separator.'crypto=cancel',
                'is_fixed_rate' => true,
                'is_fee_paid_by_user' => false,
            ]);

        $id = $response->json('id');
        $url = $response->json('invoice_url');
        if (! $response->successful() || ! is_scalar($id) || ! is_string($url) || $url === '') {
            throw new RuntimeException('NOWPayments invoice failed: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 200));
        }

        return new PaymentIntentResult((string) $id, PaymentStatus::Pending, $url);
    }

    public function parseWebhook(Request $request): ProviderEvent
    {
        if ($this->ipnSecret === '') {
            throw new InvalidWebhook('NOWPayments IPN secret is not configured.');
        }

        try {
            $data = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidWebhook('Body is not JSON.');
        }
        if (! is_array($data)) {
            throw new InvalidWebhook('Body is not a JSON object.');
        }

        $signature = (string) $request->header('x-nowpayments-sig');
        if ($signature === '' || ! hash_equals(self::sign($data, $this->ipnSecret), strtolower($signature))) {
            throw new InvalidWebhook('Invalid signature.');
        }

        $invoice = $data['invoice_id'] ?? null;
        $paymentId = $data['payment_id'] ?? null;
        $state = $data['payment_status'] ?? null;
        $price = $data['price_amount'] ?? null;
        $currency = $data['price_currency'] ?? null;
        if (! is_scalar($invoice) || (string) $invoice === '' || ! is_scalar($paymentId) || ! is_string($state) || ! is_numeric($price) || ! is_string($currency)) {
            throw new InvalidWebhook('Missing invoice, payment, status or amount.');
        }

        $status = match (true) {
            in_array($state, self::CAPTURED, true) => PaymentStatus::Captured,
            $state === 'failed' => PaymentStatus::Failed,
            $state === 'expired' => PaymentStatus::Cancelled,
            default => PaymentStatus::Pending,
        };

        return new ProviderEvent(
            eventId: $paymentId.':'.$state,
            type: 'ipn.'.$state,
            providerPaymentId: (string) $invoice,
            status: $status,
            amount: self::cents($price),
            currency: strtoupper($currency),
            payload: [
                'invoice' => (string) $invoice,
                'payment' => (string) $paymentId,
                'status' => $state,
                'pay_currency' => is_string($data['pay_currency'] ?? null) ? $data['pay_currency'] : null,
                'pay_amount' => is_scalar($data['pay_amount'] ?? null) ? (string) $data['pay_amount'] : null,
                'actually_paid' => is_scalar($data['actually_paid'] ?? null) ? (string) $data['actually_paid'] : null,
            ],
        );
    }

    public function fetchPayment(string $providerPaymentId): ProviderPaymentState
    {
        // No API-key endpoint returns an invoice's payment: the IPN decides. Pending is never final, so the sync
        // job records nothing.
        return new ProviderPaymentState(PaymentStatus::Pending, 0, 'USD');
    }

    public function refund(Payment $payment, int $amount, string $idempotencyKey): RefundResult
    {
        RiskFlag::raise('payment', $payment->id, 'manual_crypto_refund', 50, [
            'amount' => $amount,
            'currency' => $payment->currency,
            'invoice' => $payment->provider_payment_id,
            'key' => $idempotencyKey,
        ]);

        return new RefundResult('manual:'.$idempotencyKey, 'processing');
    }

    /**
     * IPN signature: HMAC-SHA512 over the body re-encoded with keys sorted (recursively), slashes unescaped.
     *
     * @param  array<mixed>  $data
     */
    public static function sign(array $data, string $secret): string
    {
        return hash_hmac('sha512', (string) json_encode(self::sortKeys($data), JSON_UNESCAPED_SLASHES), trim($secret));
    }

    /** 1030 → "10.30" (string maths). */
    public static function decimal(int $cents): string
    {
        $digits = str_pad((string) abs($cents), 3, '0', STR_PAD_LEFT);

        return ($cents < 0 ? '-' : '').substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    /** 10.3 / "10.30" → 1030. JSON numbers arrive as floats; two decimals are exact after formatting. */
    public static function cents(int|float|string $amount): int
    {
        [$units, $fraction] = array_pad(explode('.', is_string($amount) ? trim($amount) : sprintf('%.2f', $amount)), 2, '');

        return (int) $units * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function baseUrl(): string
    {
        return $this->sandbox ? 'https://api-sandbox.nowpayments.io/v1' : 'https://api.nowpayments.io/v1';
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function sortKeys(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortKeys($value);
            }
        }

        return $data;
    }
}
