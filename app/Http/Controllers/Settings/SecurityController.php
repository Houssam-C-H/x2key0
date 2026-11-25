<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domains\Identity\Actions\ChangeEmail;
use App\Domains\Identity\Actions\RevokeSessions;
use App\Domains\Identity\Actions\SetPrivacyPassword;
use App\Domains\Identity\Actions\SocialSignIn;
use App\Domains\Identity\Actions\VerifyPhone;
use App\Domains\Identity\Models\SocialAccount;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSession;
use App\Domains\Identity\Support\DeviceCookie;
use App\Http\Middleware\RequirePrivacyPassword;
use App\Shared\Support\SafePath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Security (spec 1.9 / 23): email, password, phone, 2FA, privacy password, sessions, login history.
 * Password change and 2FA endpoints are Fortify's; this controller renders the page and handles the rest.
 */
final class SecurityController
{
    public function show(Request $request, VerifyPhone $phone): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('security');
        $currentSession = $request->session()->getId();

        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        $passwordConfirmed = is_int($confirmedAt) && time() - $confirmedAt < config()->integer('auth.password_timeout', 10800);

        $pendingTwoFactor = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        $linked = SocialAccount::query()->where('user_id', $user->id)->get()->keyBy('provider');

        return Inertia::render('Settings/Security', [
            'email' => ['address' => $user->email, 'verified' => $user->hasVerifiedEmail()],
            // Connected accounts (ADR-033): configured providers and what this user linked.
            'social' => array_map(static fn (string $p): array => [
                'provider' => $p,
                'email' => $linked->get($p)?->email,
                'linked' => $linked->has($p),
            ], SocialSignIn::enabled()),
            'phone' => [
                'number' => $user->phone,
                'verified' => $user->hasVerifiedPhone(),
                'pending' => $phone->pendingPhone($user),
            ],
            'twoFactor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'pendingConfirmation' => $pendingTwoFactor,
                'qrSvg' => $pendingTwoFactor ? $user->twoFactorQrCodeSvg() : null,
                'secret' => $pendingTwoFactor ? decrypt((string) $user->two_factor_secret) : null,
                'recoveryCodes' => $request->session()->get('status') === 'two-factor-authentication-confirmed'
                    || $request->session()->get('status') === 'recovery-codes-generated'
                    ? $user->recoveryCodes() : null,
                'staffRequired' => $user->isStaff(),
            ],
            'passwordConfirmed' => $passwordConfirmed,
            'privacyPassword' => [
                'set' => $user->security?->privacy_password_hash !== null,
                'setAt' => $user->security?->privacy_password_set_at?->toIso8601String(),
            ],
            'sessions' => $user->userSessions()
                ->whereNull('revoked_at')
                ->orderByDesc('last_active_at')
                ->limit(20)
                ->get()
                ->map(fn (UserSession $s): array => [
                    'id' => $s->id,
                    'device' => DeviceCookie::label($s->user_agent),
                    'ip' => $s->ip,
                    'lastActiveAt' => $s->last_active_at->toIso8601String(),
                    'current' => $s->session_id === $currentSession,
                ])->all(),
            'loginHistory' => $user->loginEvents()
                ->latest('id')
                ->limit(10)
                ->get(['id', 'ip', 'user_agent', 'succeeded', 'created_at'])
                ->map(fn ($e): array => [
                    'id' => $e->id,
                    'device' => DeviceCookie::label($e->user_agent),
                    'ip' => $e->ip,
                    'succeeded' => $e->succeeded,
                    'at' => $e->created_at->toIso8601String(),
                ])->all(),
        ]);
    }

    public function changeEmail(Request $request, ChangeEmail $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{email: string, current_password: string} $data */
        $data = $request->validateWithBag('changeEmail', [
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')->ignore($user->id)],
            'current_password' => ['required', 'string'],
        ]);

        $action->handle($user, $data['current_password'], $data['email']);

        return back()->with('success', __('security.email.changed'));
    }

    public function sendPhoneCode(Request $request, VerifyPhone $action): RedirectResponse
    {
        /** @var array{phone: string} $data */
        $data = $request->validateWithBag('phone', [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $action->sendCode($user, $data['phone']);

        return back()->with('success', __('security.phone.sent'));
    }

    public function confirmPhone(Request $request, VerifyPhone $action): RedirectResponse
    {
        /** @var array{code: string} $data */
        $data = $request->validateWithBag('phone', ['code' => ['required', 'digits:6']]);

        /** @var User $user */
        $user = $request->user();
        $action->confirm($user, $data['code']);

        return back()->with('success', __('security.phone.verified'));
    }

    public function setPrivacyPassword(Request $request, SetPrivacyPassword $action): RedirectResponse
    {
        /** @var array{current_password: string, privacy_password: string} $data */
        $data = $request->validateWithBag('privacyPassword', [
            'current_password' => ['required', 'string'],
            'privacy_password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $data['current_password'], $data['privacy_password'], $request->session()->getId());

        return back()->with('success', __('security.privacy.saved'));
    }

    public function showPrivacyVerify(Request $request): Response
    {
        return Inertia::render('Settings/PrivacyVerify', [
            'return' => SafePath::from($request->query('return'), '/dashboard'),
        ]);
    }

    public function privacyVerify(Request $request): RedirectResponse
    {
        /** @var array{privacy_password: string, return?: string|null} $data */
        $data = $request->validate([
            'privacy_password' => ['required', 'string'],
            'return' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! SetPrivacyPassword::verify($user, $data['privacy_password'])) {
            throw ValidationException::withMessages(['privacy_password' => __('security.privacy.wrong')]);
        }

        $request->session()->put(RequirePrivacyPassword::SESSION_KEY, now()->getTimestamp());

        return redirect(SafePath::from($data['return'] ?? null, '/dashboard'));
    }

    public function revokeSession(Request $request, UserSession $session, RevokeSessions $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action->one($user, $session);

        return back()->with('success', __('security.sessions.revoked'));
    }

    public function revokeOtherSessions(Request $request, RevokeSessions $action): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password:web']]);

        /** @var User $user */
        $user = $request->user();
        $action->allExcept($user, $request->session()->getId(), 'user_request');

        return back()->with('success', __('security.sessions.revoked_others'));
    }
}
