<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff 2FA is mandatory (spec 3.2 / 23). Staff without confirmed 2FA are sent to set it up.
 * Applied to the admin panel and, from M13, to withdrawals.
 */
final class RequireStaffTwoFactor
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isStaff() && ! $user->hasTwoFactorEnabled()) {
            return redirect('/settings/security')->with('error', __('security.two_factor.staff_required'));
        }

        return $next($request);
    }
}
