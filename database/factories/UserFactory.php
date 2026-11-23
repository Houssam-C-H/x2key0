<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => Str::lower(Str::random(3)).fake()->unique()->numberBetween(10000, 99999999),
            'name' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'status' => UserStatus::Active,
            // Explicit values for columns with DB defaults, so in-memory models are complete (strict mode).
            'phone' => null,
            'phone_verified_at' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'avatar_path' => null,
            'locale' => 'en',
            'display_currency' => 'USD',
            'country' => null,
            'timezone' => 'UTC',
            'user_level_id' => null,
            'last_seen_at' => null,
            'last_login_at' => null,
            'referred_by_affiliate_id' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function withVerifiedPhone(string $phone = '+212600000000'): static
    {
        return $this->state(fn (): array => ['phone' => $phone, 'phone_verified_at' => now()]);
    }

    public function status(UserStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function withTwoFactor(): static
    {
        return $this->afterCreating(function (User $user): void {
            $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
            $user->forceFill([
                'two_factor_secret' => encrypt($secret),
                'two_factor_recovery_codes' => encrypt((string) json_encode(['recovery-code-1', 'recovery-code-2'])),
                'two_factor_confirmed_at' => now(),
            ])->save();
        });
    }

    public function staff(StaffRole $role = StaffRole::Admin): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            Role::findOrCreate($role->value, 'web');
            $user->assignRole($role->value);
        });
    }
}
