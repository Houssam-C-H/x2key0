<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline rate limit for every state-changing request (spec Part 23 "Rate limits"): routes keep their own tighter
 * limits (checkout, reveal, chat, coupons, tickets…); this catches the rest. Account-recovery endpoints (sign-up,
 * password reset, password confirmation, 2FA setup) get a strict per-IP limit against enumeration and brute force.
 * PSP webhooks (own limit, signature-authenticated) and the admin's Livewire traffic are left out.
 */
final class ThrottleWrites
{
    public const int PER_MINUTE = 120;

    public const int SENSITIVE_PER_MINUTE = 10;

    private const array SENSITIVE = ['register', 'forgot-password', 'reset-password', 'user/confirm-password', 'user/two-factor-authentication', 'user/confirmed-two-factor-authentication', 'user/two-factor-recovery-codes'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->is('webhooks/*', 'livewire*', 'horizon/*')) {
            return $next($request);
        }

        $sensitive = $request->is(...self::SENSITIVE);
        $who = $sensitive ? null : $request->user()?->getAuthIdentifier();
        $key = ($sensitive ? 'writes-sensitive:' : 'writes:').(is_scalar($who) ? 'u'.$who : 'ip'.$request->ip());
        $limit = $sensitive ? self::SENSITIVE_PER_MINUTE : self::PER_MINUTE;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response('Too many requests. Please wait a moment.', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
