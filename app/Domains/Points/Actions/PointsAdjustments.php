<?php

declare(strict_types=1);

namespace App\Domains\Points\Actions;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Ledger\Exceptions\BalanceOverdrawn;
use App\Domains\Points\Enums\PointsAccountCode;
use App\Domains\Points\Enums\PointsJournalType;
use App\Domains\Points\Models\PointsAdjustment;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\Settings;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Manual points adjustments (docs/ADMIN_PLAN.md item 7). Finance and admins, a reason is required. One balanced
 * `adjust` journal between the user's points and the platform ISSUED account; taking points back never makes the
 * balance negative. At or above `points_adjustment_dual_approval` points the request waits for another finance
 * user or admin — nobody approves their own. Every step is audited.
 */
final class PointsAdjustments
{
    public function __construct(
        private readonly PostPointsJournal $post,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function request(User $staff, User $user, int $points, string $reason): PointsAdjustment
    {
        self::assertAllowed($staff);
        $reason = trim($reason);
        if ($points === 0 || mb_strlen($reason) < 10) {
            throw new DomainException('Give a non-zero number of points and a reason of at least 10 characters.');
        }
        if ($user->is($staff)) {
            throw new DomainException('Staff cannot adjust their own points.');
        }

        return DB::transaction(function () use ($staff, $user, $points, $reason): PointsAdjustment {
            $adj = new PointsAdjustment;
            $adj->forceFill([
                'user_id' => $user->id,
                'points' => $points,
                'reason' => mb_substr($reason, 0, 1000),
                'status' => PointsAdjustment::PENDING,
                'requested_by' => $staff->id,
            ])->save();
            $this->audit->log('points.adjustment_requested', $adj, null, ['user' => $user->id, 'points' => $points, 'reason' => $reason], $staff);

            if (abs($points) < $this->settings->int('points_adjustment_dual_approval')) {
                $this->postIt($adj, $staff);
            }

            return $adj->refresh();
        });
    }

    public function approve(PointsAdjustment $adj, User $staff): PointsAdjustment
    {
        self::assertAllowed($staff);

        return DB::transaction(function () use ($adj, $staff): PointsAdjustment {
            $locked = PointsAdjustment::query()->lockForUpdate()->findOrFail($adj->id);
            if ($locked->status !== PointsAdjustment::PENDING) {
                throw new DomainException('This adjustment was already decided.');
            }
            if ($locked->requested_by === $staff->id) {
                throw new DomainException('Another staff member must approve your own request.');
            }

            $this->postIt($locked, $staff);

            return $locked->refresh();
        });
    }

    public function reject(PointsAdjustment $adj, User $staff, string $why): PointsAdjustment
    {
        self::assertAllowed($staff);

        return DB::transaction(function () use ($adj, $staff, $why): PointsAdjustment {
            $locked = PointsAdjustment::query()->lockForUpdate()->findOrFail($adj->id);
            if ($locked->status !== PointsAdjustment::PENDING) {
                throw new DomainException('This adjustment was already decided.');
            }

            $locked->forceFill(['status' => PointsAdjustment::REJECTED, 'approved_by' => $staff->id, 'rejection_reason' => mb_substr(trim($why), 0, 500), 'decided_at' => now()])->save();
            $this->audit->log('points.adjustment_rejected', $locked, null, ['reason' => $why], $staff);

            return $locked->refresh();
        });
    }

    private function postIt(PointsAdjustment $adj, User $approver): void
    {
        try {
            $journal = $this->post->handle(
                PointsJournalType::Adjust,
                "points_adjust:{$adj->id}",
                [
                    [PointsAccountCode::User, $adj->user_id, $adj->points],
                    [PointsAccountCode::Issued, 0, -$adj->points],
                ],
                'points_adjustment',
                $adj->id,
                mb_substr($adj->reason, 0, 255),
                $adj->points < 0 ? [$adj->user_id] : [],
            );
        } catch (BalanceOverdrawn) {
            throw new DomainException('The user does not have that many points to take back.');
        }

        $adj->forceFill(['status' => PointsAdjustment::POSTED, 'approved_by' => $approver->id, 'journal_id' => $journal->id, 'decided_at' => now()])->save();
        $this->audit->log('points.adjustment_posted', $adj, null, ['points' => $adj->points, 'journal' => $journal->id], $approver);
    }

    private static function assertAllowed(User $staff): void
    {
        if (! $staff->hasStaffRole(StaffRole::Finance) && ! $staff->hasStaffRole(StaffRole::Admin)) {
            throw new DomainException('Only finance and admins can adjust points.');
        }
    }
}
