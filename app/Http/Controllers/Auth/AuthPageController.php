<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Models\User;
use App\Shared\Support\SafePath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET pages for Fortify's headless auth backend (config fortify.views = false).
 * Public pages are localized; post-login pages (verify email, confirm password, 2FA challenge) are not.
 */
final class AuthPageController
{
    public function login(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function register(Request $request): Response
    {
        $ref = $request->query('ref');

        return Inertia::render('Auth/Register', [
            'referralCode' => is_string($ref) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ref) === 1 ? $ref : null,
        ]);
    }

    public function forgotPassword(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function resetPassword(Request $request, string $locale, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function twoFactorChallenge(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verifyEmail(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasVerifiedEmail()) {
            return redirect()->intended('/dashboard');
        }

        return Inertia::render('Auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function confirmPassword(Request $request): Response
    {
        // Lets a page (e.g. Security) send the user here and come back after confirming.
        if ($request->query->has('return')) {
            $request->session()->put('url.intended', SafePath::from($request->query('return'), '/settings/security'));
        }

        return Inertia::render('Auth/ConfirmPassword');
    }
}
