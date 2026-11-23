<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\SellerServiceApplication;
use Illuminate\Database\Eloquent\Model;

/**
 * Seller administration (seller profiles, service applications, merchant contacts): merchant managers
 * (spec 3.2); admins via Gate::before. Profiles are created by activation only, never from the admin,
 * and status changes go through the state machine actions (not the edit form).
 */
final class SellerPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->manages($user) && ! $model instanceof SellerServiceApplication;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** Suspend / reinstate / ban a store, or decide a service application. */
    public function moderate(User $user, Model $model): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::MerchantManager);
    }
}
