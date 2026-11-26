<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Payments\Models\PaymentEvent;
use App\Shared\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Staff "re-apply" from the payment event log (docs/ADMIN_PLAN.md item 5). Safe by construction:
 * - an event never applied (stuck in the queue) goes through ApplyPaymentEvent, which is idempotent;
 * - an event that ended as `unknown_payment` changed nothing, so it may be reopened and applied again
 *   (the payment row may have arrived after the webhook).
 * Events that moved money or raised a flag are never re-run. Finance and admins only; audited.
 */
final class ReapplyPaymentEvent
{
    public const array RETRYABLE_ERRORS = ['unknown_payment'];

    public function __construct(
        private readonly ApplyPaymentEvent $apply,
        private readonly AuditLogger $audit,
    ) {}

    public static function canRetry(PaymentEvent $event): bool
    {
        return $event->signature_valid && ($event->processed_at === null || in_array($event->error, self::RETRYABLE_ERRORS, true));
    }

    public function handle(PaymentEvent $event, User $staff): PaymentEvent
    {
        if (! $staff->hasStaffRole(StaffRole::Finance) && ! $staff->hasStaffRole(StaffRole::Admin)) {
            throw new DomainException('Only finance and admins can re-apply payment events.');
        }

        DB::transaction(function () use ($event): void {
            $locked = PaymentEvent::query()->lockForUpdate()->findOrFail($event->id);
            if (! self::canRetry($locked)) {
                throw new DomainException('This event cannot be re-applied: it was applied already or its signature is invalid.');
            }
            if ($locked->processed_at !== null) {
                $locked->forceFill(['processed_at' => null, 'error' => null])->save();
            }
        });

        $before = $event->error;
        $this->apply->handle($event->id);
        $event->refresh();
        $this->audit->log('payments.event_reapplied', $event, ['error' => $before], ['error' => $event->error, 'processed_at' => $event->processed_at?->toIso8601String()], $staff);

        return $event;
    }
}
