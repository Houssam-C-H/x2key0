<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\SellerLevel;

/**
 * Seller levels set commission, holds and reserves, so only admins (Gate::before) change them;
 * merchant managers and finance can read them.
 */
final class SellerLevelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::MerchantManager) || $user->hasStaffRole(StaffRole::Finance);
    }

    public function view(User $user, SellerLevel $level): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SellerLevel $level): bool
    {
        return false;
    }

    public function delete(User $user, SellerLevel $level): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
