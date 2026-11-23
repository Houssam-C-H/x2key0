<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Payments\Data\ProviderEvent;
use App\Domains\Payments\Jobs\ProcessPaymentEvent;
use App\Domains\Payments\Models\PaymentEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stores a verified provider event once (unique provider + event id, spec 9.3 #3) and queues its processing
 * after commit. A replay returns the stored event and queues nothing.
 */
final class RecordProviderEvent
{
    /**
     * @return array{event: PaymentEvent, duplicate: bool}
     */
    public function handle(string $provider, ProviderEvent $event): array
    {
        try {
            $stored = DB::transaction(function () use ($provider, $event): PaymentEvent {
                $row = PaymentEvent::query()->create([
                    'provider' => $provider,
                    'provider_event_id' => $event->eventId,
                    'type' => $event->type,
                    'payload' => [
                        'normalized' => [
                            'payment_id' => $event->providerPaymentId,
                            'status' => $event->status->value,
                            'amount' => $event->amount,
                            'currency' => $event->currency,
                            'card' => $event->card,
                            'three_ds' => $event->threeDs,
                            'reason' => $event->failureReason,
                        ],
                        'raw' => $event->payload,
                    ],
                    'signature_valid' => true,
                    'received_at' => now(),
                ]);

                ProcessPaymentEvent::dispatch($row->id)->afterCommit();

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            return [
                'event' => PaymentEvent::query()->where('provider', $provider)->where('provider_event_id', $event->eventId)->sole(),
                'duplicate' => true,
            ];
        }

        return ['event' => $stored, 'duplicate' => false];
    }

    /** Keeps a record of a rejected webhook (bad signature) for investigation; never processed. */
    public function rejected(string $provider, string $body, string $error): void
    {
        $payload = json_decode($body, true);

        PaymentEvent::query()->create([
            'provider' => $provider,
            'provider_event_id' => 'rejected:'.bin2hex(random_bytes(12)),
            'type' => 'rejected',
            'payload' => is_array($payload) ? $payload : ['raw' => mb_substr($body, 0, 2000)],
            'signature_valid' => false,
            'received_at' => now(),
        ])->forceFill(['processed_at' => now(), 'error' => mb_substr($error, 0, 255)])->save();
    }
}
