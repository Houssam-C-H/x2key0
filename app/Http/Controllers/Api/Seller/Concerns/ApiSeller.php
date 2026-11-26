<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Seller\Concerns;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Http\Request;

trait ApiSeller
{
    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private static function profile(Request $request): SellerProfile
    {
        $profile = SellerProfile::query()->where('user_id', self::user($request)->id)->first();
        abort_if($profile === null, 403);

        return $profile;
    }
}
