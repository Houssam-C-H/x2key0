<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Payments\Actions\RecordProviderEvent;
use App\Domains\Payments\Exceptions\InvalidWebhook;
use App\Domains\Payments\Support\PaymentRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * PSP webhooks (spec 9.3 #3): verify → store once → 200 → process in a job. No CSRF, no session; the
 * signature is the authentication. Bad signatures are recorded (never processed) and answered 400.
 */
final class PaymentWebhookController
{
    public function __invoke(Request $request, PaymentRouter $router, RecordProviderEvent $record, string $provider): JsonResponse
    {
        try {
            $driver = $router->provider($provider);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        try {
            $event = $driver->parseWebhook($request);
        } catch (InvalidWebhook $e) {
            $record->rejected($provider, $request->getContent(), $e->getMessage());

            return response()->json(['received' => false], 400);
        }

        $result = $record->handle($provider, $event);

        return response()->json(['received' => true, 'duplicate' => $result['duplicate']]);
    }
}
