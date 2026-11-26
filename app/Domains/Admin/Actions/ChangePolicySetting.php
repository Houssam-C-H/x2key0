<?php

declare(strict_types=1);

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\SettingChange;
use App\Domains\Admin\Support\PolicyCatalog;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Support\Settings;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Platform settings from the admin screen (docs/ADMIN_PLAN.md item 1). Admins only, a reason is always required.
 * Ordinary settings apply at once; money settings (PolicyCatalog::needsSecondApproval) wait for another admin —
 * nobody approves their own change, and a key has at most one pending change. Every step is audited.
 */
final class ChangePolicySetting
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  mixed  $value  the new value; ignored when $reset is true (back to the config default)
     */
    public function request(User $staff, string $key, mixed $value, string $reason, bool $reset = false): SettingChange
    {
        self::assertAdmin($staff);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Give a reason (at least 5 characters).');
        }

        try {
            $new = $reset ? config("x2key.policy.{$key}") : PolicyCatalog::normalise($key, $value);
        } catch (InvalidArgumentException $e) {
            throw new DomainException($e->getMessage(), previous: $e);
        }

        $old = $this->settings->get($key);
        $unchanged = $reset ? ! $this->settings->isOverridden($key) : $new === $old;
        if ($unchanged) {
            throw new DomainException(PolicyCatalog::label($key).': nothing changed.');
        }

        return DB::transaction(function () use ($staff, $key, $old, $new, $reason, $reset): SettingChange {
            if (SettingChange::query()->where('key', $key)->where('status', SettingChange::PENDING)->lockForUpdate()->exists()) {
                throw new DomainException(PolicyCatalog::label($key).': a change is already waiting for approval.');
            }

            $change = SettingChange::query()->create([
                'key' => $key,
                'old_value' => $old,
                'new_value' => $new,
                'is_reset' => $reset,
                'reason' => mb_substr($reason, 0, 500),
                'status' => SettingChange::PENDING,
                'requested_by' => $staff->id,
            ]);
            $this->audit->log('settings.change_requested', $change, ['value' => $old], ['key' => $key, 'value' => $new, 'reset' => $reset, 'reason' => $reason], $staff);

            if (! PolicyCatalog::needsSecondApproval($key)) {
                $this->apply($change, $staff);
            }

            return $change->refresh();
        });
    }

    public function approve(SettingChange $change, User $staff): SettingChange
    {
        self::assertAdmin($staff);

        return DB::transaction(function () use ($change, $staff): SettingChange {
            $locked = SettingChange::query()->lockForUpdate()->findOrFail($change->id);
            if ($locked->status !== SettingChange::PENDING) {
                throw new DomainException('This change was already decided.');
            }
            if ($locked->requested_by === $staff->id) {
                throw new DomainException('Another admin must approve your own change.');
            }

            $this->apply($locked, $staff);

            return $locked->refresh();
        });
    }

    public function reject(SettingChange $change, User $staff, string $why): SettingChange
    {
        self::assertAdmin($staff);

        return DB::transaction(function () use ($change, $staff, $why): SettingChange {
            $locked = SettingChange::query()->lockForUpdate()->findOrFail($change->id);
            if ($locked->status !== SettingChange::PENDING) {
                throw new DomainException('This change was already decided.');
            }

            $locked->forceFill([
                'status' => SettingChange::REJECTED,
                'decided_by' => $staff->id,
                'decided_at' => now(),
                'rejection_reason' => mb_substr(trim($why), 0, 500),
            ])->save();
            $this->audit->log('settings.change_rejected', $locked, null, ['key' => $locked->key, 'reason' => $why], $staff);

            return $locked->refresh();
        });
    }

    private function apply(SettingChange $change, User $approver): void
    {
        $change->is_reset
            ? $this->settings->reset($change->key, $approver, $change->reason)
            : $this->settings->set($change->key, $change->new_value, $approver, $change->reason);

        $change->forceFill(['status' => SettingChange::APPLIED, 'decided_by' => $approver->id, 'decided_at' => now()])->save();
    }

    private static function assertAdmin(User $staff): void
    {
        if (! $staff->hasStaffRole(StaffRole::Admin)) {
            throw new DomainException('Only admins can change platform settings.');
        }
    }
}
