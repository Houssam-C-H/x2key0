<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSecurity;
use App\Http\Middleware\RequirePrivacyPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Gives the user a Privacy Password and marks it as just verified in the test session, so Storage pages open.
 */
function unlockPrivacy(User $user): void
{
    UserSecurity::query()->updateOrCreate(
        ['user_id' => $user->id],
        ['privacy_password_hash' => Hash::make('vault-pass-1'), 'privacy_password_set_at' => now()],
    );

    test()->withSession([RequirePrivacyPassword::SESSION_KEY => now()->getTimestamp()]);
}
