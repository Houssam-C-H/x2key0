<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Payments\Data\ProviderEvent;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentRouter;
use App\Shared\Support\Settings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `payments:sync-pending` (spec 9.3 #5, every 5 min): asks the PSP about payments still open after a few
 * minutes, in case a webhook was lost. A final status becomes a synthetic event `sync:{payment}:{status}`,
 * stored and applied through the same path as webhooks — so running it twice changes nothing.
 */
final class SyncPendingPayments
{
    public function __construct(
        private readonly PaymentRouter $router,
        private readonly RecordProviderEvent $record,
        private readonly Settings $settings,
    ) {}

    public function handle(): int
    {
        $synced = 0;

        Payment::query()
            ->whereIn('status', [PaymentStatus::Created->value, PaymentStatus::Pending->value, PaymentStatus::RequiresAction->value, PaymentStatus::Authorized->value])
            ->whereNotNull('provider_payment_id')
            ->where('provider', '!=', Payment::CREDITS)
            ->where('created_at', '<=', now()->subMinutes($this->settings->int('payment_sync_after_minutes')))
            ->orderBy('id')
            ->chunkById(100, function ($payments) use (&$synced): void {
                foreach ($payments as $payment) {
                    $synced += $this->syncOne($payment) ? 1 : 0;
                }
            });

        return $synced;
    }

    /** Asks the PSP for one payment and records its final state as a sync event (idempotent per status). */
    public function syncOne(Payment $payment): bool
    {
        try {
            $state = $this->router->provider($payment->provider)->fetchPayment((string) $payment->provider_payment_id);
        } catch (Throwable $e) {
            Log::warning('Payment sync failed', ['payment' => $payment->public_id, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $state->status->isFinal()) {
            return false;
        }

        $this->record->handle($payment->provider, new ProviderEvent(
            eventId: "sync:{$payment->public_id}:{$state->status->value}",
            type: 'sync.'.$state->status->value,
            providerPaymentId: (string) $payment->provider_payment_id,
            status: $state->status,
            amount: $state->amount,
            currency: $state->currency,
            payload: ['source' => 'sync'],
        ));

        return true;
    }
}
