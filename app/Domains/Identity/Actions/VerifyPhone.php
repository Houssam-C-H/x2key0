<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Contracts\SmsSender;
use App\Domains\Identity\Models\User;
use App\Shared\Audit\AuditLogger;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Phone verification by 6-digit SMS code: 10-minute expiry, 5 attempts, 1 send per minute.
 * Only the hash of the code is kept (in cache).
 */
final class VerifyPhone
{
    private const int TTL_SECONDS = 600;

    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Cache $cache,
        private readonly SmsSender $sms,
        private readonly AuditLogger $audit,
    ) {}

    public function sendCode(User $user, string $phone): void
    {
        $limiterKey = "phone-code:{$user->id}";

        if (RateLimiter::tooManyAttempts($limiterKey, 1)) {
            throw ValidationException::withMessages([
                'phone' => __('security.phone.wait', ['seconds' => RateLimiter::availableIn($limiterKey)]),
            ])->errorBag('phone');
        }

        RateLimiter::hit($limiterKey, 60);

        $code = (string) random_int(100000, 999999);

        $this->cache->put($this->key($user), [
            'phone' => $phone,
            'hash' => Hash::make($code),
            'attempts' => 0,
        ], self::TTL_SECONDS);

        $this->sms->send($phone, __('security.phone.sms', ['code' => $code, 'app' => config()->string('x2key.brand.name')]));
    }

    public function confirm(User $user, string $code): void
    {
        /** @var array{phone: string, hash: string, attempts: int}|null $pending */
        $pending = $this->cache->get($this->key($user));

        if ($pending === null) {
            throw ValidationException::withMessages(['code' => __('security.phone.expired')])->errorBag('phone');
        }

        if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
            $this->cache->forget($this->key($user));

            throw ValidationException::withMessages(['code' => __('security.phone.too_many')])->errorBag('phone');
        }

        if (! Hash::check($code, $pending['hash'])) {
            $pending['attempts']++;
            $this->cache->put($this->key($user), $pending, self::TTL_SECONDS);

            throw ValidationException::withMessages(['code' => __('security.phone.invalid')])->errorBag('phone');
        }

        $this->cache->forget($this->key($user));

        $user->forceFill(['phone' => $pending['phone'], 'phone_verified_at' => now()])->save();
        $this->audit->log('users.phone_verified', $user);
    }

    public function pendingPhone(User $user): ?string
    {
        $pending = $this->cache->get($this->key($user));

        return is_array($pending) && is_string($pending['phone'] ?? null) ? $pending['phone'] : null;
    }

    private function key(User $user): string
    {
        return "phone-verification:{$user->id}";
    }
}
