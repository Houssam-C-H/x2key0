<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Identity\Notifications\NewDeviceLogin;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

beforeEach(fn () => Notification::fake());

it('logs in with email or username, case-insensitive', function (string $login): void {
    $user = User::factory()->create(['username' => 'player_one', 'email' => 'player@example.com']);

    $this->post('/login', ['login' => $login, 'password' => 'password'])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
})->with(['player@example.com', 'PLAYER@example.com', 'player_one', 'Player_One']);

it('records the login and the device', function (): void {
    $user = User::factory()->create();

    $this->post('/login', ['login' => $user->email, 'password' => 'password']);

    expect(LoginEvent::query()->where('user_id', $user->id)->where('succeeded', true)->count())->toBe(1)
        ->and(UserDevice::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()?->last_login_at)->not->toBeNull();
});

it('emails a new-device alert, but not for the very first device', function (): void {
    $user = User::factory()->create();

    $this->post('/login', ['login' => $user->email, 'password' => 'password']);
    Notification::assertNothingSent();

    $this->post('/logout');
    $this->flushSession();

    // A browser without the device cookie = a new device.
    $this->post('/login', ['login' => $user->email, 'password' => 'password']);
    Notification::assertSentTo($user, NewDeviceLogin::class);
});

it('does not alert again for a known device', function (): void {
    $user = User::factory()->create();
    $this->withCookie('x2k_device', 'known-device-id-0123456789abcdef');

    $this->post('/login', ['login' => $user->email, 'password' => 'password']);
    $this->post('/logout');
    $this->post('/login', ['login' => $user->email, 'password' => 'password']);

    Notification::assertNothingSent();
    expect(UserDevice::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('fails with a generic message and records the failed attempt', function (): void {
    $user = User::factory()->create();

    $this->post('/login', ['login' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['login' => __('auth.failed')]);

    $this->assertGuest();
    $event = LoginEvent::query()->where('succeeded', false)->sole();
    expect($event->identifier)->toBe($user->email);
});

it('gives the same message for unknown accounts (no user enumeration)', function (): void {
    $this->post('/login', ['login' => 'nobody@example.com', 'password' => 'whatever'])
        ->assertSessionHasErrors(['login' => __('auth.failed')]);
});

it('refuses suspended and banned accounts after a correct password', function (UserStatus $status): void {
    $user = User::factory()->status($status)->create();

    $this->post('/login', ['login' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['login' => __('auth.inactive')]);

    $this->assertGuest();
})->with([UserStatus::Suspended, UserStatus::Banned, UserStatus::Deleting]);

it('rate-limits login attempts', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->post('/login', ['login' => $user->email, 'password' => 'wrong']);
    }

    $this->post('/login', ['login' => $user->email, 'password' => 'password'])->assertStatus(429);
});

it('sends 2FA users to the challenge and logs them in with a valid code', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->post('/login', ['login' => $user->email, 'password' => 'password'])
        ->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->get('/two-factor-challenge')->assertOk();

    $code = app(Google2FA::class)->getCurrentOtp(decrypt((string) $user->fresh()?->two_factor_secret));

    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong 2FA code', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $this->post('/login', ['login' => $user->email, 'password' => 'password']);

    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('sends the challenge page back to login without a pending login', function (): void {
    $this->get('/two-factor-challenge', ['Accept-Language' => 'en'])->assertRedirect('/en/login');
});

it('redirects signed-in users away from guest pages', function (): void {
    $this->actingAs(User::factory()->create())->get('/en/login')->assertRedirect('/dashboard');
});

it('builds password reset links in the user\'s language', function (): void {
    $user = User::factory()->create(['locale' => 'ar']);

    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_contains($url, '/ar/reset-password/') && str_contains($url, urlencode($user->email));
    });
});
