<?php

declare(strict_types=1);

namespace App\Domains\Identity\Fortify;

use App\Domains\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Login with email or username. Suspended/banned/deleting accounts are refused with a clear message
 * (after the password check, so the message does not reveal account state to strangers).
 */
final class AuthenticateUser
{
    public function __invoke(Request $request): ?User
    {
        $login = $request->string('login')->trim()->lower()->toString();
        $password = $request->string('password')->toString();

        $user = User::query()
            ->where(str_contains($login, '@') ? 'email' : 'username', $login)
            ->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return null; // Fortify fires Failed and throws the generic auth.failed message
        }

        if (! $user->status->canLogIn()) {
            throw ValidationException::withMessages(['login' => __('auth.inactive')]);
        }

        return $user;
    }
}
