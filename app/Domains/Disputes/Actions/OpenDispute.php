<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Actions;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Disputes\Enums\DisputeOutcome;
use App\Domains\Disputes\Enums\DisputeReason;
use App\Domains\Disputes\Enums\DisputeRole;
use App\Domains\Disputes\Enums\DisputeStatus;
use App\Domains\Disputes\Exceptions\DisputeException;
use App\Domains\Disputes\Models\Dispute;
use App\Domains\Disputes\Models\DisputeMessage;
use App\Domains\Disputes\StateMachines\DisputeStateMachine;
use App\Domains\Disputes\Support\DisputeRules;
use App\Domains\Disputes\Support\EvidenceStore;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\SellerFunds;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Sellers\Models\SellerStats;
use App\Shared\Support\ContactInfo;
use App\Shared\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Opens a dispute (spec Part 10 / 6.4) in one transaction under the order lock: rules checked, case created
 * (open → awaiting_counterparty with the response deadline), first message and evidence stored, order marked
 * ISSUE (paid/delivering), completed orders' seller money frozen, counterparty notified after commit.
 */
final class OpenDispute
{
    public function __construct(
        private readonly DisputeRules $rules,
        private readonly DisputeStateMachine $machine,
        private readonly OrderStateMachine $orders,
        private readonly SellerFunds $funds,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array{reason: DisputeReason, resolution: DisputeOutcome, qty: int, description: string, to_credits: bool}  $data
     * @param  list<UploadedFile>  $files
     */
    public function handle(Order $order, User $user, array $data, array $files = []): Dispute
    {
        $dispute = DB::transaction(function () use ($order, $user, $data, $files): Dispute {
            /** @var Order $locked */
            $locked = Order::query()->with(['item.offer', 'seller'])->lockForUpdate()->findOrFail($order->id);
            $role = DisputeRules::roleOf($locked, $user);

            if ($role === null) {
                throw DisputeException::because('dispute.error.not_allowed');
            }

            $this->rules->assertCanOpen($locked, $role, $data['reason'], $data['resolution'], $data['qty'], count($files));

            $dispute = new Dispute;
            $dispute->forceFill([
                'order_id' => $locked->id,
                'order_item_id' => $locked->item->id,
                'opened_by_id' => $user->id,
                'opened_by_role' => $role,
                'reason' => $data['reason'],
                'status' => DisputeStatus::Open,
                'requested_resolution' => $data['resolution'] === DisputeOutcome::RefundPartial && $data['qty'] === DisputeRules::keptUnits($locked)
                    ? DisputeOutcome::RefundFull
                    : $data['resolution'],
                'requested_qty' => $data['qty'],
                'refund_to_credits' => $role === DisputeRole::Buyer && $data['to_credits'],
                'description' => $data['description'],
            ])->save();

            $this->machine->history($dispute, null, DisputeStatus::Open, $role, $user, $data['reason']->value);
            self::message($dispute, $user, $role, $data['description']);

            foreach ($files as $file) {
                EvidenceStore::store($dispute, $file, $user, $role);
            }

            $previous = $this->orders->markIssue($locked, $role === DisputeRole::Buyer ? ActorType::Buyer : ActorType::Seller, $user);
            $frozen = $locked->status === OrderStatus::Completed ? $this->funds->freeze($locked, "dispute:{$dispute->id}") : 0;

            if ($role === DisputeRole::Buyer) {
                SellerStats::query()->where('seller_profile_id', $locked->seller_profile_id)->increment('disputes_opened');
            }

            return $this->machine->transition($dispute, DisputeStatus::AwaitingCounterparty, $role, $user, null, [], function (Dispute $d) use ($previous, $frozen): void {
                $d->previous_substatus = $previous?->value;
                $d->frozen_amount = $frozen;
                $d->response_due_at = now()->addHours($this->settings->int('dispute_response_hours'));
            });
        });

        $dispute->loadMissing('order.seller');
        $counterparty = $dispute->opened_by_role === DisputeRole::Buyer ? $dispute->order->seller->user_id : $dispute->order->buyer_id;
        DB::afterCommit(fn () => Notifier::notify($counterparty, 'dispute_opened', 'dispute', self::notice($dispute)));

        return $dispute;
    }

    /** Stores a party's message (contact details masked for the other side, like chat — ADR-021). */
    public static function message(Dispute $dispute, User $author, DisputeRole $role, string $body, bool $internal = false): DisputeMessage
    {
        $redacted = $role === DisputeRole::Staff ? $body : ContactInfo::redact($body);

        $message = new DisputeMessage;
        $message->forceFill([
            'dispute_id' => $dispute->id,
            'author_id' => $author->id,
            'author_role' => $role,
            'body' => $body,
            'body_redacted' => $redacted === $body ? null : $redacted,
            'meta' => null,
            'is_internal' => $internal,
            'created_at' => now(),
        ])->save();

        return $message;
    }

    /**
     * @return array<string, string|int>
     */
    public static function notice(Dispute $dispute): array
    {
        $dispute->loadMissing('order');

        return ['number' => $dispute->order->number, 'case' => substr($dispute->public_id, -8), 'href' => $dispute->path()];
    }
}
