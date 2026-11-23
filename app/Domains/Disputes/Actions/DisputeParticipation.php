<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Exceptions\DisputeException;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\StateMachines\DisputeStateMachine;
use App\Domains\Disputes\Support\DisputeRules;
use App\Domains\Disputes\Support\EvidenceStore;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * What the two parties can do on an open case (spec 6.4 / Part 10):
 *
 * - reply (message + evidence): the counterparty's first answer moves awaiting_counterparty → negotiating;
 * - propose an outcome (refund full / partial, replacement, redelivery) → negotiating;
 * - accept: the counterparty accepts what the opener requested, or a party accepts the other's proposal →
 *   resolved by agreement (no seller fault, no violation);
 * - escalate to staff from negotiating (either party), withdraw (opener only) → cancelled.
 */
final class DisputeParticipation
{
    public function __construct(
        private readonly DisputeStateMachine $machine,
        private readonly ResolveDispute $resolve,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function reply(Dispute $dispute, User $user, string $body, array $files = []): Dispute
    {
        $role = $this->party($dispute, $user);

        return DB::transaction(function () use ($dispute, $user, $role, $body, $files): Dispute {
            $dispute = $this->lockOpen($dispute);

            if ($files !== [] && $dispute->evidence()->count() + count($files) > EvidenceStore::MAX_FILES * 2) {
                throw DisputeException::because('dispute.error.too_many_files');
            }

            if (trim($body) !== '') {
                OpenDispute::message($dispute, $user, $role, $body);
            }
            foreach ($files as $file) {
                EvidenceStore::store($dispute, $file, $user, $role);
            }

            $this->notifyOther($dispute, $role, 'dispute_reply');

            if ($dispute->status === DisputeStatus::AwaitingCounterparty && $role !== $dispute->opened_by_role) {
                return $this->machine->transition($dispute, DisputeStatus::Negotiating, $role, $user, 'reply');
            }

            return $dispute;
        });
    }

    public function propose(Dispute $dispute, User $user, DisputeOutcome $outcome, int $qty, ?string $note = null): Dispute
    {
        $role = $this->party($dispute, $user);

        return DB::transaction(function () use ($dispute, $user, $role, $outcome, $qty, $note): Dispute {
            $dispute = $this->lockOpen($dispute);

            if (! $outcome->requestable() || $dispute->status === DisputeStatus::Escalated) {
                throw DisputeException::because('dispute.error.outcome');
            }
            /** @var Order $order */
            $order = Order::query()->with('item')->findOrFail($dispute->order_id);
            $qty = $outcome === DisputeOutcome::RefundFull ? DisputeRules::keptUnits($order) : $qty;
            DisputeRules::assertOutcome($order, $outcome, $qty);

            if ($note !== null && trim($note) !== '') {
                OpenDispute::message($dispute, $user, $role, $note);
            }

            $mutate = static function (Dispute $d) use ($outcome, $qty, $role): void {
                $d->proposal_resolution = $outcome;
                $d->proposal_qty = $qty;
                $d->proposal_by_role = $role;
            };

            $this->notifyOther($dispute, $role, 'dispute_proposal');

            if ($dispute->status === DisputeStatus::AwaitingCounterparty) {
                return $this->machine->transition($dispute, DisputeStatus::Negotiating, $role, $user, 'proposal', ['outcome' => $outcome->value, 'qty' => $qty], $mutate);
            }

            $mutate($dispute);
            $dispute->save();
            $this->machine->systemLine($dispute, 'dispute.line.proposal', ['by' => $role->value, 'outcome' => $outcome->value, 'qty' => $qty]);

            return $dispute->refresh();
        });
    }

    /** Accepts the other side's proposal, or (counterparty) what the opener requested. */
    public function accept(Dispute $dispute, User $user): Dispute
    {
        $role = $this->party($dispute, $user);
        $dispute->refresh();

        if (! $dispute->status->isOpen() || $dispute->status === DisputeStatus::Escalated) {
            throw DisputeException::because('dispute.error.closed');
        }

        if ($dispute->proposal_resolution !== null && $dispute->proposal_by_role !== null && $dispute->proposal_by_role !== $role) {
            return $this->resolve->handle($dispute, $dispute->proposal_resolution, $role, $user, ['qty' => (int) $dispute->proposal_qty]);
        }

        if ($role !== $dispute->opened_by_role && $dispute->proposal_resolution === null) {
            return $this->resolve->handle($dispute, $dispute->requested_resolution, $role, $user, ['qty' => $dispute->requested_qty]);
        }

        throw DisputeException::because('dispute.error.nothing_to_accept');
    }

    public function escalate(Dispute $dispute, User $user): Dispute
    {
        $role = $this->party($dispute, $user);
        $dispute->refresh();

        if ($dispute->status !== DisputeStatus::Negotiating) {
            throw DisputeException::because('dispute.error.cannot_escalate');
        }

        $escalated = $this->machine->transition($dispute, DisputeStatus::Escalated, $role, $user, 'party');
        $this->notifyOther($escalated, $role, 'dispute_escalated');

        return $escalated;
    }

    public function withdraw(Dispute $dispute, User $user): Dispute
    {
        $role = $this->party($dispute, $user);

        if ($role !== $dispute->opened_by_role) {
            throw DisputeException::because('dispute.error.not_opener');
        }

        return $this->resolve->handle($dispute, DisputeOutcome::Cancelled, $role, $user);
    }

    /** The user's role in the case (buyer or seller of the order); anyone else is refused. */
    public function party(Dispute $dispute, User $user): DisputeRole
    {
        $dispute->loadMissing('order.seller');
        $role = DisputeRules::roleOf($dispute->order, $user);

        if ($role === null) {
            throw DisputeException::because('dispute.error.not_allowed');
        }

        return $role;
    }

    private function lockOpen(Dispute $dispute): Dispute
    {
        /** @var Dispute $locked */
        $locked = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);

        if (! $locked->status->isOpen()) {
            throw DisputeException::because('dispute.error.closed');
        }

        return $locked;
    }

    private function notifyOther(Dispute $dispute, DisputeRole $from, string $type): void
    {
        $dispute->loadMissing('order.seller');
        $to = $from === DisputeRole::Buyer ? $dispute->order->seller->user_id : $dispute->order->buyer_id;
        $data = OpenDispute::notice($dispute);

        DB::afterCommit(fn () => Notifier::notify($to, $type, 'dispute', $data));
    }
}
