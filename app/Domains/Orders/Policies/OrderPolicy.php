<?php

declare(strict_types=1);

namespace App\Domains\Orders\Policies;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;

/**
 * Staff access to orders and risk data (spec 3.2 / 23): support and merchant managers can look; finance
 * decides payment reviews and manages the blocklist and risk flags. Nobody reviews their own purchase or
 * sale. Admins pass via Gate::before (with the same self-review exception).
 */
final class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::Support) || $user->hasStaffRole(StaffRole::MerchantManager) || $user->hasStaffRole(StaffRole::Finance);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    /** Orders are never created from the admin (their resource says so); blocklist entries are. */
    public function create(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::Finance);
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasStaffRole(StaffRole::Finance) && ! $model instanceof Order;
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** Approve or reject an order in payment review. */
    public function review(User $user, Order $order): bool
    {
        return $user->hasStaffRole(StaffRole::Finance) && ! self::involved($user, $order);
    }

    public static function involved(User $user, Order $order): bool
    {
        return $order->buyer_id === $user->id || $order->seller->user_id === $user->id;
    }
}
