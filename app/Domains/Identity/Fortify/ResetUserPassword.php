<?php

declare(strict_types=1);

namespace App\Domains\Identity\Fortify;

use App\Domains\Identity\Actions\RevokeSessions;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Forgotten-password reset. Signs out every session, since the old password may be compromised.
 */
final class ResetUserPassword implements ResetsUserPasswords
{
    public function __construct(private readonly RevokeSessions $revoke) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function reset(User $user, array $input): void
    {
        /** @var array{password: string} $data */
        $data = Validator::make($input, ['password' => PasswordRules::rules()])->validate();

        $user->forceFill(['password' => $data['password']])->save();

        $this->revoke->allExcept($user, null, 'password_reset');
    }
}
