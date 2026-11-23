<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Data\PaymentContext;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentRouter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates the payment at the PSP after the order is committed (spec 9.3 #1). If the PSP refuses, the payment
 * fails and the order is cancelled, which gives stock and credits back.
 */
final class StartPayment
{
    public function __construct(
        private readonly PaymentRouter $router,
        private readonly OrderStateMachine $machine,
    ) {}

    public function handle(Order $order, Payment $payment): Payment
    {
        $order->loadMissing(['buyer', 'item']);

        try {
            $result = $this->router->provider($payment->provider)->createPayment($payment, new PaymentContext(
                returnUrl: $order->path(),
                buyerEmail: $order->buyer->email,
                buyerCountry: $order->buyer_country,
                ip: $order->ip,
                description: "X2KEY {$order->number}",
            ));
        } catch (Throwable $e) {
            Log::warning('Payment creation failed', ['order' => $order->number, 'provider' => $payment->provider, 'error' => $e->getMessage()]);
            $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_reason' => 'provider_unavailable'])->save();
            $this->machine->cancel($order, 'payment_init_failed');

            return $payment->refresh();
        }

        $payment->forceFill([
            'provider_payment_id' => $result->providerPaymentId,
            'status' => $result->status,
            'redirect_url' => $result->redirectUrl,
        ])->save();

        return $payment;
    }
}
