<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Actions;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Data\JournalDraft;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Enums\JournalType;
use App\Domains\Ledger\Exceptions\LedgerException;
use App\Domains\Ledger\Models\LedgerAdjustment;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Manual adjustments (spec 7.6): finance staff only, a reason is required; one balanced MANUAL_ADJUSTMENT journal
 * between the target account (a user balance or a platform account) and a platform offset account. Amounts at or
 * above `manual_adjustment_dual_approval_amount` (USD 100) stay pending until another finance user approves them —
 * nobody approves their own request. Every step is audited.
 *
 * Sign: a positive amount increases what the platform owes on a liability account (e.g. +5.00 to a user's Credits
 * Balance) and increases an asset or expense account; negative does the opposite.
 */
final class ManualAdjustments
{
    /** Platform accounts that may sit on the other side of an adjustment. */
    public const array OFFSETS = [AccountCode::ExpPromotions, AccountCode::ExpChargebackLoss, AccountCode::ExpPspFees, AccountCode::RevCommission, AccountCode::Bank, AccountCode::PspClearing];

    public function __construct(
        private readonly PostJournal $post,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function request(User $staff, AccountCode $account, ?int $ownerUserId, AccountCode $offset, int $amount, string $reason, string $currency = 'USD'): LedgerAdjustment
    {
        self::assertFinance($staff);
        $reason = trim($reason);
        if ($amount === 0 || mb_strlen($reason) < 10) {
            throw new LedgerException((string) __('ledger.adjust.error.input'));
        }
        if ($account->isUserOwned() !== ($ownerUserId !== null) || $offset->isUserOwned() || ! in_array($offset, self::OFFSETS, true) || $offset === $account) {
            throw new LedgerException((string) __('ledger.adjust.error.accounts'));
        }

        return DB::transaction(function () use ($staff, $account, $ownerUserId, $offset, $amount, $reason, $currency): LedgerAdjustment {
            $adj = new LedgerAdjustment;
            $adj->forceFill([
                'account_code' => $account->value,
                'owner_user_id' => $ownerUserId,
                'offset_code' => $offset->value,
                'amount' => $amount,
                'currency' => $currency,
                'reason' => mb_substr($reason, 0, 1000),
                'status' => LedgerAdjustment::PENDING,
                'requested_by' => $staff->id,
            ])->save();
            $this->audit->log('ledger.adjustment_requested', $adj, after: ['amount' => $amount, 'account' => $account->value, 'owner' => $ownerUserId], actor: $staff);

            if (abs($amount) < $this->settings->int('manual_adjustment_dual_approval_amount')) {
                $this->postIt($adj, $staff);
            }

            return $adj->refresh();
        });
    }

    public function approve(LedgerAdjustment $adj, User $staff): LedgerAdjustment
    {
        self::assertFinance($staff);

        return DB::transaction(function () use ($adj, $staff): LedgerAdjustment {
            /** @var LedgerAdjustment $locked */
            $locked = LedgerAdjustment::query()->lockForUpdate()->findOrFail($adj->id);
            if ($locked->status !== LedgerAdjustment::PENDING) {
                throw new LedgerException((string) __('ledger.adjust.error.decided'));
            }
            if ($locked->requested_by === $staff->id) {
                throw new LedgerException((string) __('ledger.adjust.error.own'));
            }

            $this->postIt($locked, $staff);

            return $locked->refresh();
        });
    }

    public function reject(LedgerAdjustment $adj, User $staff, string $why): LedgerAdjustment
    {
        self::assertFinance($staff);
        if ($adj->status !== LedgerAdjustment::PENDING) {
            throw new LedgerException((string) __('ledger.adjust.error.decided'));
        }

        $adj->forceFill(['status' => LedgerAdjustment::REJECTED, 'approved_by' => $staff->id, 'rejection_reason' => mb_substr(trim($why), 0, 500), 'decided_at' => now()])->save();
        $this->audit->log('ledger.adjustment_rejected', $adj, after: ['reason' => $why], actor: $staff);

        return $adj->refresh();
    }

    private function postIt(LedgerAdjustment $adj, User $approver): void
    {
        $account = AccountCode::from($adj->account_code);
        $target = $adj->owner_user_id === null ? AccountRef::platform($account, $adj->currency) : AccountRef::user($account, $adj->owner_user_id, $adj->currency);
        // Liabilities and revenue carry credit (−) balances: "owed more" is a negative posting on them.
        $signed = in_array($account->type()->value, ['liability', 'revenue'], true) ? -$adj->amount : $adj->amount;

        $journal = $this->post->handle(new JournalDraft(
            type: JournalType::ManualAdjustment,
            idempotencyKey: "MANUAL_ADJUSTMENT:adjustment:{$adj->id}",
            lines: [
                [$target, $signed],
                [AccountRef::platform(AccountCode::from($adj->offset_code), $adj->currency), -$signed],
            ],
            sourceType: 'ledger_adjustment',
            sourceId: $adj->id,
            description: mb_substr($adj->reason, 0, 255),
            createdByType: 'staff',
            createdById: $approver->id,
        ));

        $adj->forceFill(['status' => LedgerAdjustment::POSTED, 'approved_by' => $approver->id, 'journal_id' => $journal->id, 'decided_at' => now()])->save();
        $this->audit->log('ledger.adjustment_posted', $adj, after: ['journal' => $journal->id], actor: $approver);
    }

    private static function assertFinance(User $staff): void
    {
        if (! $staff->hasStaffRole(StaffRole::Finance) && ! $staff->hasStaffRole(StaffRole::Admin)) {
            throw new LedgerException((string) __('ledger.adjust.error.role'));
        }
    }
}
