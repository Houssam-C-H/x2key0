<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserLevel;
use App\Domains\Identity\Models\UserPreference;
use App\Domains\Identity\Models\UserSecurity;
use Illuminate\Support\Facades\DB;

final class RegisterUser
{
    /**
     * @param  array{username: string, email: string, password: string, name?: string|null, locale?: string|null, display_currency?: string|null}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = new User;
            $user->forceFill([
                'username' => mb_strtolower($data['username']),
                'name' => $data['name'] ?? $data['username'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'], // hashed by cast
                'locale' => $data['locale'] ?? app()->getLocale(),
                'display_currency' => $data['display_currency'] ?? config()->string('x2key.settlement_currency'),
                'status' => UserStatus::Active,
                'user_level_id' => UserLevel::entry()?->id,
            ])->save();

            UserPreference::query()->create(['user_id' => $user->id]);
            UserSecurity::query()->create(['user_id' => $user->id]);

            return $user->refresh(); // load DB defaults (strict mode)
        });
    }
}
