<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Auth;

/** Money widgets: admins and finance only (same rule as the Reports page). */
trait StaffAccess
{
    protected static function seesMoney(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->hasStaffRole(StaffRole::Finance) || $user->hasStaffRole(StaffRole::Admin));
    }

    protected static function usd(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
