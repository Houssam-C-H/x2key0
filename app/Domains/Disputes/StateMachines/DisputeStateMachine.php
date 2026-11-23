<?php

declare(strict_types=1);

namespace App\Domains\Disputes\StateMachines;

use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Exceptions\DisputeException;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Models\DisputeMessage;
use App\Domains\Disputes\Models\DisputeStatusHistory;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of disputes.status (spec 6.4):
 *
 * open → awaiting_counterparty (response_due_at = now + dispute_response_hours)
 * awaiting_counterparty → negotiating (reply / proposal) | escalated (deadline passed) | resolved (counterparty accepts)
 * negotiating → resolved (proposal accepted) | escalated (either party)
 * escalated → resolved (staff decision)
 * any open status → cancelled (opener withdraws) · staff may decide any open case
 *
 * Every transition locks the dispute row and writes a dispute_status_history row and a system line in the thread.
 */
final class DisputeStateMachine
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  (callable(Dispute, DisputeStatus): void)|null  $mutate
     */
    public function transition(Dispute $dispute, DisputeStatus $to, DisputeRole $actor, ?User $by, ?string $reason = null, array $meta = [], ?callable $mutate = null): Dispute
    {
        return DB::transaction(function () use ($dispute, $to, $actor, $by, $reason, $meta, $mutate): Dispute {
            /** @var Dispute $locked */
            $locked = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw DisputeException::because('dispute.error.transition', ['from' => $from->value, 'to' => $to->value]);
            }

            $locked->status = $to;
            match ($to) {
                DisputeStatus::Escalated => $locked->escalated_at = now(),
                DisputeStatus::Resolved => $locked->resolved_at = now(),
                DisputeStatus::Cancelled => $locked->cancelled_at = now(),
                default => null,
            };
            if (! $to->isOpen() || $to === DisputeStatus::Escalated) {
                $locked->response_due_at = null;
            }
            if ($mutate !== null) {
                $mutate($locked, $from);
            }
            $locked->save();

            $this->history($locked, $from, $to, $actor, $by, $reason, $meta);
            $this->systemLine($locked, "dispute.line.{$to->value}", ['by' => $actor->value]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function history(Dispute $dispute, ?DisputeStatus $from, DisputeStatus $to, DisputeRole $actor, ?User $by, ?string $reason = null, array $meta = []): void
    {
        (new DisputeStatusHistory)->forceFill([
            'dispute_id' => $dispute->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actor,
            'actor_id' => $by?->id,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ])->save();
    }

    /**
     * A translated line in the thread (key + params, translated for each reader).
     *
     * @param  array<string, string|int>  $params
     */
    public function systemLine(Dispute $dispute, string $key, array $params = []): void
    {
        (new DisputeMessage)->forceFill([
            'dispute_id' => $dispute->id,
            'author_id' => null,
            'author_role' => DisputeRole::System,
            'body' => $key,
            'body_redacted' => null,
            'meta' => ['key' => $key, 'params' => $params],
            'is_internal' => false,
            'created_at' => now(),
        ])->save();
    }
}
