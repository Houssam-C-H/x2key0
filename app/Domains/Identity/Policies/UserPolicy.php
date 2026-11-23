<?php

declare(strict_types=1);

namespace App\Domains\Identity\Policies;

use App\Domains\Identity\Models\User;

/**
 * Staff access to user records. Admins pass every check via Gate::before.
 * Users never manage other users; they edit themselves through Settings controllers.
 */
final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isStaff();
    }

    public function view(User $actor, User $subject): bool
    {
        return $actor->isStaff() || $actor->is($subject);
    }

    /** Status and role changes: admin only (handled by Gate::before). */
    public function update(User $actor, User $subject): bool
    {
        return false;
    }

    public function create(User $actor): bool
    {
        return false; // accounts are created by registration only
    }

    public function delete(User $actor, User $subject): bool
    {
        return false; // deletion goes through the account-deletion flow (spec 6.8, M18)
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
