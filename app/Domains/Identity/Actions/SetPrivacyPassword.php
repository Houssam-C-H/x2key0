<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSecurity;
use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Privacy Password (spec 1.9): a second password that gates Storage and other sensitive actions.
 * Must differ from the login password. Setting or changing it signs out every other session.
 */
final class SetPrivacyPassword
{
    public function __construct(
        private readonly RevokeSessions $revoke,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $user, string $currentAccountPassword, string $newPrivacyPassword, ?string $currentSessionId): void
    {
        if (! Hash::check($currentAccountPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('auth.password')])->errorBag('privacyPassword');
        }

        if (Hash::check($newPrivacyPassword, $user->password)) {
            throw ValidationException::withMessages(['privacy_password' => __('security.privacy.must_differ')])->errorBag('privacyPassword');
        }

        $security = UserSecurity::query()->firstOrCreate(['user_id' => $user->id]);
        $isChange = $security->privacy_password_hash !== null;

        $security->forceFill([
            'privacy_password_hash' => Hash::make($newPrivacyPassword),
            'privacy_password_set_at' => now(),
        ])->save();

        $this->audit->log($isChange ? 'privacy_password.changed' : 'privacy_password.set', $user);
        $this->revoke->allExcept($user, $currentSessionId, 'privacy_password_changed');
    }

    public static function verify(User $user, string $privacyPassword): bool
    {
        $hash = UserSecurity::query()->whereKey($user->id)->value('privacy_password_hash');

        return is_string($hash) && Hash::check($privacyPassword, $hash);
    }
}
