<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSession;
use App\Shared\Audit\AuditLogger;
use DomainException;

/**
 * Staff account actions (spec Part 18 "Users"): suspend, ban, reactivate (each with a reason), reset two-factor
 * authentication, sign the user out everywhere. Staff never act on themselves; deleted accounts are final.
 * Every action is audited.
 */
final class ModerateUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function setStatus(User $user, UserStatus $status, User $staff, string $reason): User
    {
        if (! in_array($status, [UserStatus::Active, UserStatus::Suspended, UserStatus::Banned], true)) {
            throw new DomainException('Only active, suspended or banned can be set here.');
        }
        $this->guard($user, $staff);
        if (trim($reason) === '') {
            throw new DomainException('A reason is required.');
        }

        $before = $user->status->value;
        $user->forceFill(['status' => $status])->save();
        if ($status !== UserStatus::Active) {
            $this->signOut($user, $staff, 'status_'.$status->value);
        }
        $this->audit->log('user.status_changed', $user, before: ['status' => $before], after: ['status' => $status->value, 'reason' => mb_substr($reason, 0, 500)], actor: $staff);

        return $user->refresh();
    }

    public function resetTwoFactor(User $user, User $staff, string $reason): User
    {
        $this->guard($user, $staff);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->log('user.two_factor_reset', $user, after: ['reason' => mb_substr($reason, 0, 500)], actor: $staff);

        return $user->refresh();
    }

    public function signOut(User $user, User $staff, string $reason): int
    {
        $count = UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $user->forceFill(['remember_token' => null])->saveQuietly();
        $this->audit->log('user.signed_out_everywhere', $user, after: ['sessions' => $count, 'reason' => $reason], actor: $staff);

        return $count;
    }

    private function guard(User $user, User $staff): void
    {
        if ($user->id === $staff->id) {
            throw new DomainException('Staff cannot act on their own account.');
        }
        if ($user->status === UserStatus::Deleted) {
            throw new DomainException('Deleted accounts cannot be changed.');
        }
    }
}
