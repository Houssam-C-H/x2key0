<?php

declare(strict_types=1);

namespace App\Domains\Payouts\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Actions\PostJournal;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Exceptions\BalanceOverdrawn;
use App\Domains\Payouts\Enums\WithdrawalStatus;
use App\Domains\Payouts\Exceptions\PayoutException;
use App\Domains\Payouts\Models\PayoutMethod;
use App\Domains\Payouts\Models\PayoutMethodType;
use App\Domains\Payouts\Models\WithdrawalRequest;
use App\Domains\Payouts\StateMachines\WithdrawalStateMachine;
use App\Domains\Payouts\Support\WithdrawalRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Withdraw wizard, last step (spec 1.8: Balance → Withdraw to → Amount → Confirm). The amount leaves the Available
 * Balance at once (WITHDRAWAL_REQUESTED into PAYOUT_IN_TRANSIT) under the balance row lock with a no-overdraw
 * guard, so concurrent requests can never withdraw more than is available (spec 7.4, invariant 4.3 #2).
 * Idempotent per (user, idempotency key): a double submit returns the same request.
 */
final class RequestWithdrawal
{
    public function __construct(
        private readonly WithdrawalRules $rules,
        private readonly PostJournal $post,
        private readonly WithdrawalStateMachine $machine,
    ) {}

    public function handle(User $user, int $payoutMethodId, int $amount, string $idempotencyKey, string $currency = 'USD'): WithdrawalRequest
    {
        $existing = WithdrawalRequest::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        if (($blockers = $this->rules->blockers($user)) !== []) {
            throw PayoutException::because("payout.blocked.{$blockers[0]}");
        }

        $method = PayoutMethod::query()->usable()->where('user_id', $user->id)->whereKey($payoutMethodId)->first();
        $type = $method === null ? null : PayoutMethodType::query()->where('code', $method->type)->where('is_enabled', true)->first();
        if ($method === null || $type === null) {
            throw PayoutException::because('payout.error.method');
        }

        $minimum = $this->rules->minimum($type);
        if ($amount < $minimum) {
            throw PayoutException::because('payout.error.minimum', ['amount' => number_format($minimum / 100, 2)]);
        }
        $fee = $this->rules->fee($type, $amount, $currency);
        if ($fee >= $amount) {
            throw PayoutException::because('payout.error.fee');
        }

        try {
            return DB::transaction(function () use ($user, $method, $amount, $fee, $currency, $idempotencyKey): WithdrawalRequest {
                $w = new WithdrawalRequest;
                $w->forceFill([
                    'user_id' => $user->id,
                    'source' => 'seller',
                    'amount' => $amount,
                    'currency' => $currency,
                    'fee_amount' => $fee,
                    'payout_amount' => $amount - $fee,
                    'payout_currency' => $currency,
                    'payout_method_id' => $method->id,
                    'payout_label' => $method->masked_label,
                    'status' => WithdrawalStatus::Requested,
                    'idempotency_key' => mb_substr($idempotencyKey, 0, 64),
                    'requested_at' => now(),
                ])->save();

                $available = AccountRef::user(AccountCode::SellerAvailable, $user->id, $currency);
                $this->post->handle(new JournalDraft(
                    type: JournalType::WithdrawalRequested,
                    idempotencyKey: "WITHDRAWAL_REQUESTED:withdrawal:{$w->id}",
                    lines: [
                        [$available, $amount],
                        [AccountRef::user(AccountCode::PayoutInTransit, $user->id, $currency), -$amount],
                    ],
                    sourceType: 'withdrawal',
                    sourceId: $w->id,
                    description: "Withdrawal {$w->number()} requested",
                    noOverdraw: [$available],
                    createdByType: 'user',
                    createdById: $user->id,
                ));

                $this->machine->history($w, null, WithdrawalStatus::Requested, 'user', $user);

                return $w->refresh();
            });
        } catch (BalanceOverdrawn) {
            throw PayoutException::because('payout.error.insufficient');
        } catch (UniqueConstraintViolationException) {
            // The same submit raced itself: the other one won.
            return WithdrawalRequest::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }
}
