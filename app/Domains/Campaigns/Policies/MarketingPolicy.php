<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Platform coupons, campaigns, applications and the Affiliate Program admin (spec Part 18): merchant managers; admins via Gate::before. */
final class MarketingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::MerchantManager);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->viewAny($user);
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
