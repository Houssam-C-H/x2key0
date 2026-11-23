<?php

declare(strict_types=1);

namespace App\Domains\Disputes\Policies;

use App\Domains\Disputes\Models\Dispute;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Dispute administration (spec 3.2): support staff read cases, add internal notes and decide them; admins via
 * Gate::before. Nobody decides a case about their own order (enforced for admins too in Gate::before).
 * Parties act through the Dispute Center, never the admin.
 */
final class DisputePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->staff($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->staff($user);
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

    public function decide(User $user, Dispute $dispute): bool
    {
        return $this->staff($user) && ! self::involved($user, $dispute);
    }

    public static function involved(User $user, Dispute $dispute): bool
    {
        $dispute->loadMissing('order.seller');

        return $dispute->order->buyer_id === $user->id || $dispute->order->seller->user_id === $user->id;
    }

    private function staff(User $user): bool
    {
        return $user->hasStaffRole(StaffRole::Support);
    }
}
