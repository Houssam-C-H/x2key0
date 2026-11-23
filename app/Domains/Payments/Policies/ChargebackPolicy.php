<?php

declare(strict_types=1);

namespace App\Domains\Payments\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Chargebacks (spec 9.4): finance records them and marks them won or lost; admins via Gate::before. */
final class ChargebackPolicy
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
        return $user->hasStaffRole(StaffRole::Finance);
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
