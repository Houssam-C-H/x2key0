<?php

declare(strict_types=1);

namespace App\Domains\Content\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Home modules, news, help, pages and SEO rows (spec 3.2: moderators handle catalog, offers, reviews, news; support
 * writes help articles). Admins via Gate::before. Home modules themselves are never created or deleted (12 fixed).
 */
final class ContentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::Moderator) || $user->hasStaffRole(StaffRole::Support);
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
        return $this->viewAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
