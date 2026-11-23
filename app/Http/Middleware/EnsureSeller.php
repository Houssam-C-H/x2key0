<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Models\User;
use App\Shared\Navigation\DashboardMenu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seller Center and Storage pages: users without a store that opens the Seller Center
 * (active, vacation, suspended — spec 6.3) are sent to seller onboarding.
 */
final class EnsureSeller
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect(DashboardMenu::ONBOARDING_PATH);
        }

        // Re-read the store: its status can change between requests (suspension, ban).
        $user->unsetRelation('sellerProfile');

        if (! $user->isSeller()) {
            return redirect(DashboardMenu::ONBOARDING_PATH);
        }

        return $next($request);
    }
}
