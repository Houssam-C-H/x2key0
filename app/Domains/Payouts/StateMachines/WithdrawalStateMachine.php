<?php

declare(strict_types=1);

namespace App\Domains\Payouts\StateMachines;

use App\Domains\Announcements\Support\Notifier;
use App\Domains\Identity\Models\User;
use App\Domains\Invoices\Actions\IssueInvoices;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Payouts\Enums\WithdrawalStatus;
use App\Domains\Payouts\Exceptions\PayoutException;
use App\Domains\Payouts\Models\WithdrawalRequest;
use App\Domains\Payouts\Models\WithdrawalStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of withdrawal_requests.status (spec 6.6), with the money of each step (spec 7.2):
 *
 * - requested (RequestWithdrawal): WITHDRAWAL_REQUESTED — SELLER_AVAILABLE + amount, PAYOUT_IN_TRANSIT − amount;
 * - rejected / cancelled: WITHDRAWAL_RELEASED — the reverse;
 * - approved → processing: no money moves;
 * - paid: WITHDRAWAL_PAID — PAYOUT_IN_TRANSIT + amount, BANK − payout, REV_PAYOUT_FEE − fee; a withdrawal voucher is issued;
 * - failed: WITHDRAWAL_FAILED — PAYOUT_IN_TRANSIT + amount, SELLER_AVAILABLE − amount (the money goes back).
 *
 * Staff never act on their own withdrawal. Each transition writes a history row in the same transaction.
 */
final class WithdrawalStateMachine
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly IssueInvoices $invoices,
    ) {}

    public function approve(WithdrawalRequest $w, User $staff): WithdrawalRequest
    {
        $done = $this->transition($w, WithdrawalStatus::Approved, 'staff', $staff, null, function (WithdrawalRequest $l) use ($staff): void {
            $l->approved_at = now();
            $l->reviewed_by = $staff->id;
        });
        $this->notify($done, 'withdrawal_approved');

        return $done;
    }

    public function reject(WithdrawalRequest $w, User $staff, string $reason): WithdrawalRequest
    {
        $done = $this->transition($w, WithdrawalStatus::Rejected, 'staff', $staff, $reason, function (WithdrawalRequest $l) use ($staff, $reason): void {
            $l->reviewed_by = $staff->id;
            $l->remarks = mb_substr($reason, 0, 500);
            $l->closed_at = now();
            $this->release($l, JournalType::WithdrawalReleased, 'rejected');
        });
        $this->notify($done, 'withdrawal_rejected');

        return $done;
    }

    public function cancel(WithdrawalRequest $w, User $user): WithdrawalRequest
    {
        if ($w->user_id !== $user->id) {
            throw PayoutException::because('payout.error.not_yours');
        }

        return $this->transition($w, WithdrawalStatus::Cancelled, 'user', $user, null, function (WithdrawalRequest $l): void {
            $l->closed_at = now();
            $this->release($l, JournalType::WithdrawalReleased, 'cancelled');
        });
    }

    public function process(WithdrawalRequest $w, User $staff): WithdrawalRequest
    {
        return $this->transition($w, WithdrawalStatus::Processing, 'staff', $staff);
    }

    public function pay(WithdrawalRequest $w, User $staff, string $providerRef): WithdrawalRequest
    {
        $done = $this->transition($w, WithdrawalStatus::Paid, 'staff', $staff, null, function (WithdrawalRequest $l) use ($providerRef): void {
            $l->paid_at = now();
            $l->closed_at = now();
            $l->provider_ref = mb_substr(trim($providerRef), 0, 191);

            $lines = [
                [AccountRef::user(AccountCode::PayoutInTransit, $l->user_id, $l->currency), $l->amount],
                [AccountRef::platform(AccountCode::Bank, $l->currency), -$l->payout_amount],
            ];
            if ($l->fee_amount > 0) {
                $lines[] = [AccountRef::platform(AccountCode::RevPayoutFee, $l->currency), -$l->fee_amount];
            }

            $this->post->handle(new JournalDraft(
                type: JournalType::WithdrawalPaid,
                idempotencyKey: "WITHDRAWAL_PAID:withdrawal:{$l->id}",
                lines: $lines,
                sourceType: 'withdrawal',
                sourceId: $l->id,
                description: "Withdrawal {$l->number()} paid",
            ));

            $l->save();
            $this->invoices->voucher($l);
        });
        $this->notify($done, 'withdrawal_paid');

        return $done;
    }

    public function fail(WithdrawalRequest $w, User $staff, string $reason): WithdrawalRequest
    {
        $done = $this->transition($w, WithdrawalStatus::Failed, 'staff', $staff, $reason, function (WithdrawalRequest $l) use ($reason): void {
            $l->failure_reason = mb_substr($reason, 0, 500);
            $l->closed_at = now();
            $this->release($l, JournalType::WithdrawalFailed, 'failed');
        });
        $this->notify($done, 'withdrawal_failed');

        return $done;
    }

    public function history(WithdrawalRequest $w, ?WithdrawalStatus $from, WithdrawalStatus $to, string $actor, ?User $by, ?string $reason = null): void
    {
        (new WithdrawalStatusHistory)->forceFill([
            'withdrawal_request_id' => $w->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actor,
            'actor_id' => $by?->id,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'created_at' => now(),
        ])->save();
    }

    /**
     * @param  (callable(WithdrawalRequest): void)|null  $mutate
     */
    private function transition(WithdrawalRequest $w, WithdrawalStatus $to, string $actor, ?User $by, ?string $reason = null, ?callable $mutate = null): WithdrawalRequest
    {
        return DB::transaction(function () use ($w, $to, $actor, $by, $reason, $mutate): WithdrawalRequest {
            /** @var WithdrawalRequest $locked */
            $locked = WithdrawalRequest::query()->lockForUpdate()->findOrFail($w->id);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw PayoutException::because('payout.error.transition', ['from' => $from->value, 'to' => $to->value]);
            }
            if ($actor === 'staff' && $by !== null && $by->id === $locked->user_id) {
                throw PayoutException::because('payout.error.self');
            }

            $locked->status = $to;
            if ($mutate !== null) {
                $mutate($locked);
            }
            $locked->save();
            $this->history($locked, $from, $to, $actor, $by, $reason);

            return $locked->refresh();
        });
    }

    /** Money held for the withdrawal goes back to the Available Balance. */
    private function release(WithdrawalRequest $w, JournalType $type, string $why): void
    {
        $this->post->handle(new JournalDraft(
            type: $type,
            idempotencyKey: "{$type->value}:withdrawal:{$w->id}",
            lines: [
                [AccountRef::user(AccountCode::PayoutInTransit, $w->user_id, $w->currency), $w->amount],
                [AccountRef::user(AccountCode::SellerAvailable, $w->user_id, $w->currency), -$w->amount],
            ],
            sourceType: 'withdrawal',
            sourceId: $w->id,
            description: "Withdrawal {$w->number()} {$why}",
        ));
    }

    private function notify(WithdrawalRequest $w, string $type): void
    {
        $data = ['number' => $w->number(), 'amount' => number_format($w->amount / 100, 2).' '.$w->currency, 'href' => '/seller/withdrawals'];
        $userId = $w->user_id;

        DB::afterCommit(fn () => Notifier::notify($userId, $type, 'merchant', $data));
    }
}
