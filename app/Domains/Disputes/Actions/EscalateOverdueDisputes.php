<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Exceptions\DisputeException;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\StateMachines\DisputeStateMachine;

/**
 * `disputes:escalate-overdue` (every 5 min, idempotent): a counterparty that did not answer before
 * `response_due_at` sends the case to staff (spec 6.4 awaiting_counterparty → escalated).
 */
final class EscalateOverdueDisputes
{
    public function __construct(private readonly DisputeStateMachine $machine) {}

    public function handle(): int
    {
        $count = 0;

        Dispute::query()
            ->where('status', DisputeStatus::AwaitingCounterparty->value)
            ->where('response_due_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($disputes) use (&$count): void {
                foreach ($disputes as $dispute) {
                    try {
                        $escalated = $this->machine->transition($dispute, DisputeStatus::Escalated, DisputeRole::System, null, 'deadline', [], static function (Dispute $d, DisputeStatus $from): void {
                            // Re-checked under the row lock: an answer may have arrived since the query.
                            if ($from !== DisputeStatus::AwaitingCounterparty) {
                                throw DisputeException::because('dispute.error.transition', ['from' => $from->value, 'to' => DisputeStatus::Escalated->value]);
                            }
                        });
                    } catch (DisputeException) {
                        continue; // answered or closed concurrently
                    }

                    $escalated->loadMissing('order.seller');
                    $data = OpenDispute::notice($escalated);
                    Notifier::notify($escalated->order->buyer_id, 'dispute_escalated', 'dispute', $data);
                    Notifier::notify($escalated->order->seller->user_id, 'dispute_escalated', 'dispute', $data);
                    $count++;
                }
            });

        return $count;
    }
}
