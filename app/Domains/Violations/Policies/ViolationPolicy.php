<?php

declare(strict_types=1);

namespace App\Domains\Violations\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Violations\Models\Violation;
use Illuminate\Database\Eloquent\Model;

/**
 * Violation Center administration (spec 3.2): merchant managers raise violations and decide appeals; rule
 * points and penalties are edited by admins only (Gate::before). Nobody decides their own store's case.
 */
final class ViolationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::MerchantManager);
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasStaffRole(StaffRole::MerchantManager);
    }

    public function create(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::MerchantManager);
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function decide(User $user, Violation $violation): bool
    {
        $violation->loadMissing('seller');

        return $user->hasStaffRole(StaffRole::MerchantManager) && $violation->seller->user_id !== $user->id;
    }
}
