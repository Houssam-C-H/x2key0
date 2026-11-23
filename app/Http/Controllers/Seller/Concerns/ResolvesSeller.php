<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller\Concerns;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Http\Request;

/** Seller Center controllers run behind the `seller` middleware, so the profile exists. */
trait ResolvesSeller
{
    /** Always read from the database: status may have changed since the user was loaded. */
    private function seller(Request $request): SellerProfile
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $profile = SellerProfile::query()->where('user_id', $user->id)->first();
        abort_if($profile === null, 403);

        return $profile;
    }
}
