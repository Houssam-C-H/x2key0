<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Shared\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reveal of delivered secrets requires a recent login-password confirmation (spec 8.3,
 * `reveal_requires_reauth_minutes`). Uses the session timestamp Fortify's password confirmation writes.
 * JSON requests get 423 with the confirmation URL; page requests are redirected there.
 */
final class RequireRecentPassword
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        $window = $this->settings->int('reveal_requires_reauth_minutes') * 60;

        if (is_numeric($confirmedAt) && time() - (int) $confirmedAt < $window) {
            return $next($request);
        }

        $back = $request->headers->get('referer') ?: '/buy/orders';
        $request->session()->put('url.intended', $back);

        if ($request->expectsJson()) {
            return response()->json(['message' => __('order.reveal.confirm_password'), 'confirm_url' => route('password.confirm')], 423);
        }

        return redirect()->route('password.confirm');
    }
}
