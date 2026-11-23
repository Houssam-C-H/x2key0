<?php

declare(strict_types=1);

namespace App\Domains\Payments\Providers;

use App\Domains\Payments\Contracts\PaymentProvider;
use App\Domains\Payments\Data\PaymentContext;
use App\Domains\Payments\Data\PaymentIntentResult;
use App\Domains\Payments\Data\ProviderEvent;
use App\Domains\Payments\Data\ProviderPaymentState;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\InvalidWebhook;
use App\Domains\Payments\Models\Payment;
use Brick\Money\Money;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use SensitiveParameter;

/**
 * Development/test PSP (D2 default until M6b). It keeps its own "PSP-side" state in the cache, sends the
 * buyer to a local hosted page (/fake-psp/{id}) where they choose the outcome, and signs webhooks with
 * HMAC-SHA256 like a real PSP. Refuses to run in production.
 *
 * Webhook body: {"id": "evt_…", "type": "payment.captured|payment.failed|payment.pending",
 *   "data": {"payment_id": "fake_…", "amount": 1234, "currency": "USD", "three_ds": true,
 *            "card": {"bin": "424242", "last4": "4242", "country": "MA"}}}
 * Header: X-Fake-Signature = hex HMAC-SHA256(raw body, secret).
 */
final class FakePaymentProvider implements PaymentProvider
{
    public const string CODE = 'fake';

    public const string SIGNATURE_HEADER = 'X-Fake-Signature';

    private const string PREFIX = 'fake-psp:';

    public function __construct(
        private readonly Cache $cache,
        #[SensitiveParameter] private readonly string $secret,
        private readonly bool $production,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function supports(string $methodCode, ?string $country, Money $amount): bool
    {
        return ! $this->production;
    }

    public function createPayment(Payment $payment, PaymentContext $context): PaymentIntentResult
    {
        if ($this->production) {
            throw new LogicException('The fake PSP is disabled in production.');
        }

        $id = 'fake_'.strtolower((string) Str::ulid());
        $this->put($id, ['status' => PaymentStatus::Pending->value, 'amount' => $payment->amount, 'currency' => $payment->currency, 'return_url' => $context->returnUrl, 'description' => $context->description]);

        return new PaymentIntentResult($id, PaymentStatus::Pending, "/fake-psp/{$id}");
    }

    public function parseWebhook(Request $request): ProviderEvent
    {
        $body = $request->getContent();
        $signature = $request->header(self::SIGNATURE_HEADER);

        if (! is_string($signature) || ! hash_equals($this->sign($body), $signature)) {
            throw new InvalidWebhook('Invalid signature.');
        }

        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidWebhook('Unreadable body.');
        }

        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_string($data['type'] ?? null) || ! is_array($data['data'] ?? null)) {
            throw new InvalidWebhook('Missing fields.');
        }

        $eventId = $data['id'];
        $type = $data['type'];
        $d = $data['data'];
        $payload = [];
        foreach ($data as $key => $value) {
            $payload[(string) $key] = $value;
        }

        $status = match ($type) {
            'payment.captured' => PaymentStatus::Captured,
            'payment.failed' => PaymentStatus::Failed,
            'payment.cancelled' => PaymentStatus::Cancelled,
            'payment.pending' => PaymentStatus::Pending,
            default => throw new InvalidWebhook('Unknown event type.'),
        };

        if (! is_string($d['payment_id'] ?? null) || ! is_int($d['amount'] ?? null) || ! is_string($d['currency'] ?? null)) {
            throw new InvalidWebhook('Missing payment fields.');
        }

        $card = is_array($d['card'] ?? null) ? [
            'bin' => is_string($d['card']['bin'] ?? null) ? $d['card']['bin'] : null,
            'last4' => is_string($d['card']['last4'] ?? null) ? $d['card']['last4'] : null,
            'country' => is_string($d['card']['country'] ?? null) ? $d['card']['country'] : null,
        ] : null;

        return new ProviderEvent(
            eventId: $eventId,
            type: $type,
            providerPaymentId: $d['payment_id'],
            status: $status,
            amount: $d['amount'],
            currency: $d['currency'],
            payload: $payload,
            card: $card,
            threeDs: ($d['three_ds'] ?? false) === true,
            failureReason: is_string($d['reason'] ?? null) ? mb_substr($d['reason'], 0, 255) : null,
        );
    }

    public function fetchPayment(string $providerPaymentId): ProviderPaymentState
    {
        $state = $this->get($providerPaymentId);

        if ($state === null) {
            return new ProviderPaymentState(PaymentStatus::Pending, 0, 'USD');
        }

        return new ProviderPaymentState(PaymentStatus::from($state['status']), $state['amount'], $state['currency']);
    }

    /**
     * Hosted-page outcome: records the PSP-side state and returns the signed webhook it sends.
     *
     * @return array{body: string, signature: string, returnUrl: string}
     */
    public function complete(string $providerPaymentId, PaymentStatus $outcome): array
    {
        $state = $this->get($providerPaymentId) ?? throw new LogicException('Unknown fake payment.');
        $state['status'] = $outcome->value;
        $this->put($providerPaymentId, $state);

        $body = $this->webhookBody($providerPaymentId, $outcome, $state['amount'], $state['currency']);

        return ['body' => $body, 'signature' => $this->sign($body), 'returnUrl' => $state['return_url']];
    }

    /**
     * @return array{status: string, amount: int, currency: string, return_url: string, description: string}|null
     */
    public function state(string $providerPaymentId): ?array
    {
        return $this->get($providerPaymentId);
    }

    /** Builds a webhook body exactly as the fake PSP sends it (also used by tests). */
    public function webhookBody(string $providerPaymentId, PaymentStatus $status, int $amount, string $currency, ?string $eventId = null): string
    {
        return json_encode([
            'id' => $eventId ?? 'evt_'.strtolower((string) Str::ulid()),
            'type' => 'payment.'.$status->value,
            'data' => [
                'payment_id' => $providerPaymentId,
                'amount' => $amount,
                'currency' => $currency,
                'three_ds' => true,
                'card' => ['bin' => '424242', 'last4' => '4242', 'country' => null],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->secret);
    }

    /**
     * @param  array{status: string, amount: int, currency: string, return_url: string, description: string}  $state
     */
    private function put(string $id, array $state): void
    {
        $this->cache->put(self::PREFIX.$id, $state, now()->addDays(2));
    }

    /**
     * @return array{status: string, amount: int, currency: string, return_url: string, description: string}|null
     */
    private function get(string $id): ?array
    {
        $state = $this->cache->get(self::PREFIX.$id);

        if (! is_array($state) || ! is_string($state['status'] ?? null) || ! is_int($state['amount'] ?? null) || ! is_string($state['currency'] ?? null)) {
            return null;
        }

        return [
            'status' => $state['status'],
            'amount' => $state['amount'],
            'currency' => $state['currency'],
            'return_url' => is_string($state['return_url'] ?? null) ? $state['return_url'] : '/',
            'description' => is_string($state['description'] ?? null) ? $state['description'] : '',
        ];
    }
}
