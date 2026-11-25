<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Models\User;
use App\Shared\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * Break-glass 2FA reset from the server console, for when no other staff member can do it in the admin panel
 * (e.g. the only admin lost their authenticator and recovery codes). Staff resets go through ModerateUser; this
 * one is audited as a console action with the operator's reason.
 */
final class UsersResetTwoFactor extends Command
{
    protected $signature = 'users:reset-two-factor {username : Username or e-mail} {--reason= : Why (kept in the audit log)}';

    protected $description = 'Turn off two-step verification for one account (console only, audited)';

    public function handle(AuditLogger $audit): int
    {
        $login = mb_strtolower(trim((string) $this->argument('username')));
        $user = User::query()->where(str_contains($login, '@') ? 'email' : 'username', $login)->first();
        if ($user === null) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('Give a reason with --reason="…" (it goes into the audit log).');

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled() && $user->two_factor_secret === null) {
            $this->info("{$user->username} has no two-step verification.");

            return self::SUCCESS;
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $audit->log('user.two_factor_reset', $user, after: ['reason' => mb_substr($reason, 0, 500), 'via' => 'console']);

        $this->info("Two-step verification turned off for {$user->username}. They must set it up again (staff accounts need it to open /admin).");

        return self::SUCCESS;
    }
}
