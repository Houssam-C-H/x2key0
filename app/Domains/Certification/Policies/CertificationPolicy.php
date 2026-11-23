<?php

declare(strict_types=1);

namespace App\Domains\Certification\Policies;

use App\Domains\Certification\Models\Certification;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;

/**
 * Certification review queue: support and merchant managers review; admins via Gate::before.
 */
final class CertificationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isReviewer($actor);
    }

    public function view(User $actor, Certification $certification): bool
    {
        return $this->isReviewer($actor) || $certification->user_id === $actor->id;
    }

    public function review(User $actor, Certification $certification): bool
    {
        // Nobody reviews their own verification, not even an admin.
        return $this->isReviewer($actor) && $certification->user_id !== $actor->id;
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, Certification $certification): bool
    {
        return false;
    }

    public function delete(User $actor, Certification $certification): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    private function isReviewer(User $actor): bool
    {
        return $actor->hasStaffRole(StaffRole::Support) || $actor->hasStaffRole(StaffRole::MerchantManager);
    }
}
