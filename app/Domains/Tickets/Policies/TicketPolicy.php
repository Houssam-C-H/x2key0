<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Ticket queue and canned replies (spec 3.2): support staff; admins via Gate::before. Users act on their own
 * tickets through the dashboard, never the admin.
 */
final class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::Support);
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasStaffRole(StaffRole::Support);
    }

    public function create(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::Support);
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasStaffRole(StaffRole::Support);
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
