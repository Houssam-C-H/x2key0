<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use LogicException;

/** Who is using the admin, for screens and actions without a model policy. Admins pass every check. */
final class Staff
{
    public static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public static function current(): User
    {
        return self::user() ?? throw new LogicException('Staff only.');
    }

    public static function has(StaffRole ...$roles): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }

        foreach ([StaffRole::Admin, ...$roles] as $role) {
            if ($user->hasStaffRole($role)) {
                return true;
            }
        }

        return false;
    }
}
