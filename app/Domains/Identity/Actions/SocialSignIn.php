<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Exceptions\SocialLoginException;
use App\Domains\Identity\Fortify\CreateNewUser;
use App\Domains\Identity\Models\SocialAccount;
use App\Domains\Identity\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;

/**
 * Social login (ADR-033): turns a Google / Facebook identity into an X2KEY user.
 *
 * 1. A linked identity logs its user in.
 * 2. Otherwise an existing account with the same e-mail is linked only when the provider vouches for that e-mail
 *    (Google `email_verified`; Facebook only returns confirmed e-mails) — never on an unverified address, so nobody
 *    can take over an account by creating a provider account with someone else's e-mail.
 * 3. Otherwise a new account is created like a normal sign-up (same RegisterUser action, Registered event for
 *    affiliate attribution), with a generated username and an unknown random password (reset it to set one).
 */
final class SocialSignIn
{
    /** @var list<string> */
    public const array PROVIDERS = ['google', 'facebook'];

    public function __construct(private readonly RegisterUser $register) {}

    /**
     * Providers with credentials configured (the only ones shown and routed).
     *
     * @return list<string>
     */
    public static function enabled(): array
    {
        return array_values(array_filter(self::PROVIDERS, static fn (string $p): bool => filled(config("services.{$p}.client_id")) && filled(config("services.{$p}.client_secret"))));
    }

    /**
     * @return array{user: User, created: bool}
     */
    public function handle(string $provider, SocialUser $identity, ?string $displayCurrency = null): array
    {
        $id = (string) $identity->getId();
        if ($id === '') {
            throw SocialLoginException::because('auth.social.failed');
        }

        $linked = SocialAccount::query()->where('provider', $provider)->where('provider_user_id', $id)->with('user')->first();
        if ($linked !== null) {
            $linked->forceFill(['last_used_at' => now()])->save();

            return ['user' => self::assertCanLogIn($linked->user), 'created' => false];
        }

        $email = mb_strtolower(trim((string) $identity->getEmail()));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw SocialLoginException::because('auth.social.no_email');
        }
        $verified = self::emailVerified($provider, $identity);

        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            if (! $verified) {
                throw SocialLoginException::because('auth.social.email_taken');
            }
            self::assertCanLogIn($existing);
            $this->link($existing, $provider, $id, $email);

            return ['user' => $existing, 'created' => false];
        }

        $user = DB::transaction(function () use ($provider, $id, $email, $identity, $verified, $displayCurrency): User {
            $user = $this->register->handle([
                'username' => self::username($identity, $email),
                'name' => Str::limit(trim((string) $identity->getName()) ?: Str::before($email, '@'), 120, ''),
                'email' => $email,
                'password' => Str::password(40),
                'locale' => app()->getLocale(),
                'display_currency' => $displayCurrency,
            ]);
            if ($verified) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            $this->link($user, $provider, $id, $email);

            return $user->refresh();
        });

        event(new Registered($user));

        return ['user' => $user, 'created' => true];
    }

    /** Settings › Connected accounts: link an identity to the signed-in user. */
    public function connect(User $user, string $provider, SocialUser $identity): void
    {
        $id = (string) $identity->getId();
        $taken = SocialAccount::query()->where('provider', $provider)->where('provider_user_id', $id)->first();
        if ($taken !== null && $taken->user_id !== $user->id) {
            throw SocialLoginException::because('auth.social.linked_elsewhere');
        }
        if ($taken === null) {
            SocialAccount::query()->where('user_id', $user->id)->where('provider', $provider)->delete();
            $this->link($user, $provider, $id, $identity->getEmail());
        }
    }

    private function link(User $user, string $provider, string $id, ?string $email): void
    {
        SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $id,
            'email' => $email === null ? null : mb_strtolower($email),
            'last_used_at' => now(),
        ]);
    }

    private static function assertCanLogIn(User $user): User
    {
        if (! $user->status->canLogIn()) {
            throw SocialLoginException::because('auth.inactive');
        }

        return $user;
    }

    private static function emailVerified(string $provider, SocialUser $identity): bool
    {
        $raw = method_exists($identity, 'getRaw') ? $identity->getRaw() : [];

        return match ($provider) {
            'google' => is_array($raw) && in_array($raw['email_verified'] ?? $raw['verified_email'] ?? false, [true, 'true', 1, '1'], true),
            'facebook' => true, // Graph API only returns a confirmed primary e-mail
            default => false,
        };
    }

    /** A free, valid username from the nickname / name / e-mail ("Jane Doe" → "jane_doe", "jane_doe2", …). */
    private static function username(SocialUser $identity, string $email): string
    {
        $seed = (string) ($identity->getNickname() ?: $identity->getName() ?: Str::before($email, '@'));
        $base = Str::of(Str::ascii($seed))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(24, '')->toString();
        if (mb_strlen($base) < 3 || in_array($base, CreateNewUser::RESERVED, true)) {
            $base = 'player_'.Str::lower(Str::random(5));
        }

        $candidate = $base;
        for ($i = 2; User::query()->where('username', $candidate)->exists(); $i++) {
            $candidate = $base.($i < 100 ? $i : '_'.Str::lower(Str::random(4)));
        }

        return $candidate;
    }
}
