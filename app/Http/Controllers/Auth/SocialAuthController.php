<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Actions\SocialSignIn;
use App\Domains\Identity\Exceptions\SocialLoginException;
use App\Domains\Identity\Models\SocialAccount;
use App\Domains\Identity\Models\User;
use App\Http\Middleware\ResolveLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Social login (ADR-033): "Continue with Google / Facebook" and Settings › Connected accounts.
 * Accounts with two-step verification still go through the 2FA challenge.
 */
final class SocialAuthController
{
    private const string CONNECT_KEY = 'social.connect';

    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        abort_unless(in_array($provider, SocialSignIn::enabled(), true), 404);

        // Signed-in users come here from Settings to connect an account.
        $request->session()->put(self::CONNECT_KEY, $request->user() !== null);

        $driver = Socialite::driver($provider);
        if ($provider === 'facebook' && method_exists($driver, 'scopes')) {
            $driver->scopes(['email']);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, SocialSignIn $action, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, SocialSignIn::enabled(), true), 404);
        $connecting = $request->session()->pull(self::CONNECT_KEY, false) === true;
        $login = '/'.app()->getLocale().'/login';

        if ($request->query('error') !== null) { // the user cancelled on the provider's page
            return redirect($connecting ? '/settings/security' : $login);
        }

        try {
            $identity = Socialite::driver($provider)->user();
        } catch (Throwable $e) {
            Log::warning('social login callback failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect($connecting ? '/settings/security' : $login)->with('error', __('auth.social.failed'));
        }

        $current = $request->user();
        if ($connecting && $current instanceof User) {
            try {
                $action->connect($current, $provider, $identity);
            } catch (SocialLoginException $e) {
                return redirect('/settings/security#connected')->with('error', $e->getMessage());
            }

            return redirect('/settings/security#connected')->with('success', __('auth.social.connected', ['provider' => self::label($provider)]));
        }

        try {
            ['user' => $user, 'created' => $created] = $action->handle($provider, $identity, ResolveLocale::displayCurrency($request));
        } catch (SocialLoginException $e) {
            return redirect($login)->with('error', $e->getMessage());
        }

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => true]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return $created
            ? redirect('/dashboard')->with('success', __('auth.social.welcome'))
            : redirect()->intended(config()->string('fortify.home'));
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        SocialAccount::query()->where('user_id', $user->id)->where('provider', $provider)->first()?->delete();

        return back()->with('success', __('auth.social.disconnected', ['provider' => self::label($provider)]));
    }

    public static function label(string $provider): string
    {
        return match ($provider) {
            'google' => 'Google',
            'facebook' => 'Facebook',
            default => ucfirst($provider),
        };
    }
}
