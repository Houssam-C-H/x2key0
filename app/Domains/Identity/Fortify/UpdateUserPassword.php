<?php

declare(strict_types=1);

namespace App\Domains\Identity\Fortify;

use App\Domains\Identity\Actions\RevokeSessions;
use App\Domains\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * Password change from Settings › Security. Signs out every other session (spec 1.9).
 */
final class UpdateUserPassword implements UpdatesUserPasswords
{
    public function __construct(
        private readonly RevokeSessions $revoke,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        /** @var array{password: string} $data */
        $data = Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => PasswordRules::rules(),
        ])->validateWithBag('updatePassword');

        $user->forceFill(['password' => $data['password']])->save();

        $this->revoke->allExcept($user, $this->request->hasSession() ? $this->request->session()->getId() : null, 'password_changed');
    }
}
