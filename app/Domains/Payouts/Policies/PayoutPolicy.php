<?php

declare(strict_types=1);

namespace App\Domains\Payouts\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Withdrawals, payout method catalog and invoices in the admin (spec 3.2): finance; admins via Gate::before.
 * Nobody acts on their own withdrawal (also enforced by WithdrawalStateMachine).
 */
final class PayoutPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::Finance);
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasStaffRole(StaffRole::Finance);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasStaffRole(StaffRole::Finance);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
