<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSession;
use App\Shared\Audit\AuditLogger;

/**
 * Revokes sessions in the registry. TrackUserSession logs a revoked session out on its next request,
 * whatever the session driver is.
 */
final class RevokeSessions
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function one(User $user, UserSession $session): void
    {
        abort_unless($session->user_id === $user->id, 404);

        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now()])->save();
            $this->audit->log('user_sessions.revoked', $session, after: ['session' => $session->id]);
        }
    }

    /**
     * @return int number of sessions revoked
     */
    public function allExcept(User $user, ?string $currentSessionId, string $reason): int
    {
        $count = UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($currentSessionId !== null, fn ($q) => $q->where('session_id', '!=', $currentSessionId))
            ->update(['revoked_at' => now()]);

        // Remember-me tokens would otherwise silently log the revoked browsers back in.
        $user->forceFill(['remember_token' => null])->saveQuietly();

        if ($count > 0) {
            $this->audit->log('user_sessions.revoked_others', $user, after: ['count' => $count, 'reason' => $reason]);
        }

        return $count;
    }
}
