<?php

declare(strict_types=1);

use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\InvalidWebhook;
use App\Domains\Payments\Models\PaymentMethod;
use App\Domains\Payments\Providers\StripePaymentProvider;
use App\Domains\Payments\Support\PaymentRouter;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Http\Request;

/*
| Stripe Checkout driver (M6b): webhook signatures and event mapping, offered only when configured. Live API calls
| (sessions, refunds) are exercised manually with test keys; CI never talks to Stripe.
*/

function stripeEvent(string $type, string $paymentStatus = 'paid', string $status = 'complete'): string
{
    return json_encode([
        'id' => 'evt_test_'.$type,
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => [
            'id' => 'cs_test_123',
            'object' => 'checkout.session',
            'amount_total' => 1030,
            'currency' => 'usd',
            'payment_status' => $paymentStatus,
            'status' => $status,
        ]],
    ], JSON_THROW_ON_ERROR);
}

function stripeRequest(string $body, string $secret): Request
{
    $t = time();
    $signature = hash_hmac('sha256', "{$t}.{$body}", $secret);

    return Request::create('/webhooks/stripe', 'POST', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$signature}", 'CONTENT_TYPE' => 'application/json'], $body);
}

it('maps signed checkout events to payment statuses', function (string $type, string $paymentStatus, PaymentStatus $expected): void {
    $provider = new StripePaymentProvider('sk_test_x', 'whsec_test');
    $event = $provider->parseWebhook(stripeRequest(stripeEvent($type, $paymentStatus), 'whsec_test'));

    expect($event->status)->toBe($expected)
        ->and($event->providerPaymentId)->toBe('cs_test_123')
        ->and($event->amount)->toBe(1030)
        ->and($event->currency)->toBe('USD');
})->with([
    ['checkout.session.completed', 'paid', PaymentStatus::Captured],
    ['checkout.session.completed', 'unpaid', PaymentStatus::Pending],
    ['checkout.session.async_payment_succeeded', 'paid', PaymentStatus::Captured],
    ['checkout.session.async_payment_failed', 'unpaid', PaymentStatus::Failed],
    ['checkout.session.expired', 'unpaid', PaymentStatus::Cancelled],
]);

it('refuses forged signatures, unknown events and a missing webhook secret', function (): void {
    $provider = new StripePaymentProvider('sk_test_x', 'whsec_test');

    expect(fn () => $provider->parseWebhook(stripeRequest(stripeEvent('checkout.session.completed'), 'whsec_other')))->toThrow(InvalidWebhook::class)
        ->and(fn () => $provider->parseWebhook(stripeRequest(stripeEvent('charge.refunded'), 'whsec_test')))->toThrow(InvalidWebhook::class)
        ->and(fn () => (new StripePaymentProvider('sk_test_x', ''))->parseWebhook(stripeRequest(stripeEvent('checkout.session.completed'), 'whsec_test')))->toThrow(InvalidWebhook::class);
});

it('is offered at checkout only when STRIPE_SECRET is set', function (): void {
    (new PaymentMethodSeeder)->run();
    expect(PaymentMethod::query()->where('code', 'stripe_card')->value('provider'))->toBe('stripe');

    config(['x2key.payments.stripe.secret' => '']);
    expect(collect(app(PaymentRouter::class)->available(null, 'USD', 1000))->pluck('code')->all())->not->toContain('stripe_card');

    config(['x2key.payments.stripe.secret' => 'sk_test_x']);
    expect(collect(app(PaymentRouter::class)->available(null, 'USD', 1000))->pluck('code')->all())->toContain('stripe_card');
});

it('an invalid Stripe signature on the webhook endpoint is refused and never applied', function (): void {
    config(['x2key.payments.stripe.webhook_secret' => 'whsec_test']);
    $body = stripeEvent('checkout.session.completed');

    $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad', 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(400);
});
