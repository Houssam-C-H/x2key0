<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Identity\Fortify\AuthenticateUser;
use App\Domains\Identity\Fortify\CreateNewUser;
use App\Domains\Identity\Fortify\ResetUserPassword;
use App\Domains\Identity\Fortify\UpdateUserPassword;
use App\Domains\Identity\Models\User;
use App\Http\Middleware\ResolveLocale;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::authenticateUsing(new AuthenticateUser);
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Reset links are built explicitly with the user's locale (no URL defaults outside a request).
        ResetPassword::createUrlUsing(static function (mixed $user, string $token): string {
            $locale = $user instanceof User && in_array($user->locale, ResolveLocale::supportedLocales(), true)
                ? $user->locale
                : config()->string('x2key.default_locale');
            $email = $user instanceof User ? $user->email : '';

            return url("/{$locale}/reset-password/{$token}").'?'.http_build_query(['email' => $email]);
        });

        RateLimiter::for('login', function (Request $request): array {
            $login = Str::transliterate($request->string(Fortify::username())->lower()->toString());

            return [
                Limit::perMinute(5)->by($login.'|'.$request->ip()),
                Limit::perMinute(30)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            $pendingUserId = $request->session()->get('login.id');

            return Limit::perMinute(5)->by(is_scalar($pendingUserId) ? 'user:'.$pendingUserId : 'ip:'.$request->ip());
        });
    }
}
