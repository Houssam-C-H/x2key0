<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Catalog administration (category types, games, game categories, products, platforms, regions):
 * moderators manage it (spec 3.2); admins pass via Gate::before. Category types are seeded and can be
 * edited (names, order, enabled) but never created or deleted, since code depends on the 15 codes.
 */
final class CatalogPolicy
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
        return $this->manages($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::Moderator);
    }
}
