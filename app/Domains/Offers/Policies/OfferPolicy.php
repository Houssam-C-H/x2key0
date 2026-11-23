<?php

declare(strict_types=1);

namespace App\Domains\Offers\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Models\Offer;
use Illuminate\Database\Eloquent\Model;

/**
 * Staff access to offers and storage groups (spec 3.2 / Part 18): moderators run the offer moderation
 * queue; merchant managers can look. Storage groups are read-only counts in the admin (never decrypted).
 * Sellers manage their own records through the Seller Center, not through this policy.
 * Admins pass via Gate::before — except moderating an offer of their own store.
 */
final class OfferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::Moderator) || $user->hasStaffRole(StaffRole::MerchantManager);
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

    /** Approve, reject, system-deactivate, restore. */
    public function moderate(User $user, Offer $offer): bool
    {
        return $user->hasStaffRole(StaffRole::Moderator) && $offer->seller->user_id !== $user->id;
    }
}
