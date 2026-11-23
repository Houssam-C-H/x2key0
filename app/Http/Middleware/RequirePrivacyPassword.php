<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSecurity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates sensitive areas (Storage from M5, account deletion in M18) behind the Privacy Password
 * (spec 1.7: "Enter Privacy Password"). A successful check is valid for N minutes in this session.
 */
final class RequirePrivacyPassword
{
    public const string SESSION_KEY = 'privacy_password_verified_at';

    public const int VALID_SECONDS = 900;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $hasPrivacyPassword = UserSecurity::query()->whereKey($user->id)->whereNotNull('privacy_password_hash')->exists();

        if (! $hasPrivacyPassword) {
            return redirect('/settings/security')->with('error', __('security.privacy.set_first'));
        }

        $verifiedAt = $request->session()->get(self::SESSION_KEY);

        if (! is_int($verifiedAt) || now()->getTimestamp() - $verifiedAt > self::VALID_SECONDS) {
            return redirect('/security/privacy-verify?'.http_build_query(['return' => $request->getRequestUri()]));
        }

        return $next($request);
    }
}
