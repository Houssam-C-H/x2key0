<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Changing the email needs the current password, resets verification and sends a new link.
 */
final class ChangeEmail
{
    public function handle(User $user, string $currentPassword, string $newEmail): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('auth.password')])->errorBag('changeEmail');
        }

        $user->forceFill([
            'email' => mb_strtolower($newEmail),
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
