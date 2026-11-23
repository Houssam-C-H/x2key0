<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Payments\Actions\RecordProviderEvent;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Providers\FakePaymentProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hosted page of the fake PSP (dev/test only; 404 in production). The buyer picks the outcome; the fake PSP
 * then sends its signed webhook through the same verification and storage path as a real one, and returns
 * the buyer to the order page.
 */
final class FakePspController
{
    public function show(FakePaymentProvider $psp, string $id): Response
    {
        abort_if(app()->isProduction(), 404);
        $state = $psp->state($id);
        abort_if($state === null, 404);

        return Inertia::render('Checkout/FakePsp', [
            'id' => $id,
            'amount' => $state['amount'],
            'currency' => $state['currency'],
            'description' => $state['description'],
            'status' => $state['status'],
            'returnUrl' => $state['return_url'],
        ]);
    }

    public function complete(Request $request, FakePaymentProvider $psp, RecordProviderEvent $record, string $id, string $outcome): RedirectResponse
    {
        abort_if(app()->isProduction(), 404);
        $state = $psp->state($id);
        abort_if($state === null || $state['status'] !== PaymentStatus::Pending->value, 404);

        $status = match ($outcome) {
            'pay' => PaymentStatus::Captured,
            'fail' => PaymentStatus::Failed,
            default => PaymentStatus::Cancelled,
        };

        $webhook = $psp->complete($id, $status);
        $signed = Request::create('/webhooks/fake', 'POST', server: ['HTTP_'.str_replace('-', '_', strtoupper(FakePaymentProvider::SIGNATURE_HEADER)) => $webhook['signature'], 'CONTENT_TYPE' => 'application/json'], content: $webhook['body']);
        $record->handle(FakePaymentProvider::CODE, $psp->parseWebhook($signed));

        return redirect($webhook['returnUrl']);
    }
}
