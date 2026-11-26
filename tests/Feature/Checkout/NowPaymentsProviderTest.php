<?php

declare(strict_types=1);

use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\InvalidWebhook;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentEvent;
use App\Domains\Payments\Providers\NowPaymentsProvider;
use App\Domains\Payments\Support\PaymentRouter;
use App\Domains\Risk\Models\RiskFlag;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CheckoutFixture;

/*
| NOWPayments crypto driver (ADR-041): hosted invoice, IPN signature (HMAC-SHA512 over key-sorted JSON), status
| mapping, amounts without floats, offered only when configured. CI never talks to NOWPayments (Http::fake).
*/

/** @param array<string, mixed> $overrides */
function nowIpn(array $overrides = []): array
{
    return [
        'payment_id' => 5077125051,
        'invoice_id' => 4522625843,
        'payment_status' => 'finished',
        'pay_address' => '0xabc',
        'price_amount' => 10.3,
        'price_currency' => 'usd',
        'pay_amount' => 0.0042,
        'actually_paid' => 0.0042,
        'pay_currency' => 'eth',
        'order_id' => 'pay_1',
        'order_description' => 'Order',
        'outcome_amount' => 10.1,
        'outcome_currency' => 'usdttrc20',
        ...$overrides,
    ];
}

/** @param array<string, mixed> $data */
function nowRequest(array $data, string $secret): Request
{
    $body = (string) json_encode($data);

    return Request::create('/webhooks/nowpayments', 'POST', [], [], [], ['HTTP_X_NOWPAYMENTS_SIG' => NowPaymentsProvider::sign($data, $secret), 'CONTENT_TYPE' => 'application/json'], $body);
}

it('maps signed IPN statuses and reads the USD amount without floats', function (string $state, PaymentStatus $expected): void {
    $event = (new NowPaymentsProvider('key', 'ipn-secret'))->parseWebhook(nowRequest(nowIpn(['payment_status' => $state]), 'ipn-secret'));

    expect($event->status)->toBe($expected)
        ->and($event->providerPaymentId)->toBe('4522625843')
        ->and($event->eventId)->toBe("5077125051:{$state}")
        ->and($event->amount)->toBe(1030)
        ->and($event->currency)->toBe('USD');
})->with([
    ['waiting', PaymentStatus::Pending],
    ['confirming', PaymentStatus::Pending],
    ['partially_paid', PaymentStatus::Pending],
    ['confirmed', PaymentStatus::Captured],
    ['finished', PaymentStatus::Captured],
    ['failed', PaymentStatus::Failed],
    ['expired', PaymentStatus::Cancelled],
]);

it('refuses forged or missing signatures, bad bodies and a missing IPN secret', function (): void {
    $provider = new NowPaymentsProvider('key', 'ipn-secret');
    $forged = nowRequest(nowIpn(), 'other-secret');
    $missing = Request::create('/webhooks/nowpayments', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(nowIpn()));
    $tampered = nowRequest(nowIpn(), 'ipn-secret');
    $tampered->initialize([], [], [], [], [], $tampered->server->all(), (string) json_encode(nowIpn(['price_amount' => 1])));

    expect(fn () => $provider->parseWebhook($forged))->toThrow(InvalidWebhook::class)
        ->and(fn () => $provider->parseWebhook($missing))->toThrow(InvalidWebhook::class)
        ->and(fn () => $provider->parseWebhook($tampered))->toThrow(InvalidWebhook::class)
        ->and(fn () => (new NowPaymentsProvider('key', ''))->parseWebhook(nowRequest(nowIpn(), 'ipn-secret')))->toThrow(InvalidWebhook::class)
        ->and(NowPaymentsProvider::cents(10.3))->toBe(1030)
        ->and(NowPaymentsProvider::cents('0.07'))->toBe(7)
        ->and(NowPaymentsProvider::decimal(1030))->toBe('10.30');
});

it('is offered only with both keys, sends the buyer to the invoice and marks the order paid on the IPN', function (): void {
    (new PaymentMethodSeeder)->run();
    expect(collect(app(PaymentRouter::class)->available(null, 'USD', 1000))->pluck('code')->all())->not->toContain('nowpayments_crypto');

    config(['x2key.payments.nowpayments.api_key' => 'np-key', 'x2key.payments.nowpayments.ipn_secret' => 'ipn-secret']);
    expect(collect(app(PaymentRouter::class)->available(null, 'USD', 1000))->pluck('code')->all())->toContain('nowpayments_crypto');

    Http::fake(['api.nowpayments.io/v1/invoice' => Http::response(['id' => '4522625843', 'invoice_url' => 'https://nowpayments.io/payment/?iid=4522625843'], 200)]);
    $fx = CheckoutFixture::build();
    $this->actingAs($fx['buyer'])->post('/checkout', CheckoutFixture::form($fx['manual'], ['method' => 'nowpayments_crypto']));
    $order = CheckoutFixture::lastOrder($fx['buyer']);
    $payment = Payment::query()->where('order_id', $order->id)->where('provider', 'nowpayments')->sole();

    expect($payment->provider_payment_id)->toBe('4522625843')
        ->and($payment->redirect_url)->toContain('nowpayments.io');
    Http::assertSent(fn (HttpRequest $r): bool => $r->hasHeader('x-api-key', 'np-key')
        && $r['order_id'] === $payment->public_id
        && $r['price_currency'] === 'usd'
        && str_ends_with((string) $r['ipn_callback_url'], '/webhooks/nowpayments'));

    $ipn = nowIpn(['invoice_id' => 4522625843, 'price_amount' => (float) NowPaymentsProvider::decimal($payment->amount), 'order_id' => $payment->public_id]);
    $send = fn (array $data) => $this->call('POST', '/webhooks/nowpayments', [], [], [], ['HTTP_X_NOWPAYMENTS_SIG' => NowPaymentsProvider::sign($data, 'ipn-secret'), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], (string) json_encode($data));

    $send([...$ipn, 'payment_status' => 'confirming'])->assertOk();
    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);

    $send($ipn)->assertOk();
    $send($ipn)->assertOk()->assertJson(['duplicate' => true]); // repeated IPN stored once
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Captured)
        ->and(PaymentEvent::query()->where('provider', 'nowpayments')->count())->toBe(2);
});

it('records refunds as processing and flags them for finance, since NOWPayments has no refund API', function (): void {
    $fx = CheckoutFixture::build();
    $this->actingAs($fx['buyer'])->post('/checkout', CheckoutFixture::form($fx['manual']));
    $payment = CheckoutFixture::pspPayment(CheckoutFixture::lastOrder($fx['buyer']));

    $result = (new NowPaymentsProvider('key', 'ipn'))->refund($payment, 500, 'REFUND:order:1:1');

    expect($result->status)->toBe('processing')
        ->and(RiskFlag::query()->where('rule', 'manual_crypto_refund')->where('subject_id', $payment->id)->exists())->toBeTrue()
        ->and((new NowPaymentsProvider('key', 'ipn'))->fetchPayment('x')->status->isFinal())->toBeFalse();
});
