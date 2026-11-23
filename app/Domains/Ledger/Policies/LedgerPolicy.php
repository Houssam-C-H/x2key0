<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Ledger in the admin (spec Part 18 "Ledger (read-only; manual adjustment with dual approval)"): finance
 * reads it; nobody edits it — postings only come from PostJournal. Manual adjustments arrive with M18.
 */
final class LedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::Finance);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
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
}
