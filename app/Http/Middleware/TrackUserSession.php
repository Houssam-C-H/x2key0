<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Identity\Models\UserSession;
use App\Domains\Identity\Support\DeviceCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the `user_sessions` registry in sync and enforces revocation and account status
 * on every authenticated request. Writes are throttled to once a minute per session.
 */
final class TrackUserSession
{
    private const int TOUCH_EVERY_SECONDS = 60;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $sessionId = $request->session()->getId();
        $record = UserSession::query()->where('session_id', $sessionId)->first();

        if (($record !== null && $record->revoked_at !== null) || ! $user->status->canLogIn()) {
            return $this->logout($request);
        }

        if ($record === null) {
            $fingerprint = DeviceCookie::fingerprint($request);
            $record = UserSession::query()->create([
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'user_device_id' => UserDevice::query()->where('user_id', $user->id)->where('fingerprint', $fingerprint)->value('id'),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'last_active_at' => now(),
            ]);
        } elseif ($record->last_active_at->diffInSeconds(now()) >= self::TOUCH_EVERY_SECONDS) {
            $record->forceFill(['last_active_at' => now(), 'ip' => $request->ip()])->save();
        }

        // A just-created model may not have the column loaded (strict mode): treat as never seen.
        $lastSeen = array_key_exists('last_seen_at', $user->getAttributes()) ? $user->last_seen_at : null;

        if ($lastSeen === null || $lastSeen->diffInSeconds(now()) >= self::TOUCH_EVERY_SECONDS) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    private function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/'.ResolveLocale::preferredLocale($request).'/login')
            ->with('error', __('auth.session_ended'));
    }
}
