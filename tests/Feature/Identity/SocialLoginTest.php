<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\SocialAccount;
use App\Domains\Identity\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * Social login (ADR-033): Google / Facebook sign-in, safe account linking, 2FA, Settings › Connected accounts.
 */

beforeEach(function (): void {
    config([
        'services.google.client_id' => 'google-id', 'services.google.client_secret' => 'google-secret',
        'services.facebook.client_id' => null, 'services.facebook.client_secret' => null,
    ]);
});

/** The provider returns this identity on the callback. */
function fakeIdentity(string $provider, string $id, ?string $email, string $name = 'Jane Doe', bool $verified = true): void
{
    $identity = (new SocialiteUser)->setRaw(['email_verified' => $verified])->map(['id' => $id, 'email' => $email, 'name' => $name, 'nickname' => null]);
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($identity);
    $driver->shouldReceive('redirect')->andReturn(new RedirectResponse('https://accounts.example/'.$provider));
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('driver')->with($provider)->andReturn($driver);
    Socialite::swap($factory);
}

it('shows only configured providers, and 404s the others', function (): void {
    $this->get('/en/login')->assertInertia(fn (Assert $page) => $page->where('socialProviders', ['google']));
    $this->get('/en/register')->assertInertia(fn (Assert $page) => $page->where('socialProviders', ['google']));

    $this->get('/auth/facebook/redirect')->assertNotFound();
    $this->get('/auth/twitter/redirect')->assertNotFound();
    expect($this->get('/auth/google/redirect')->headers->get('Location'))->toStartWith('https://accounts.google.com/');
});

it('creates a verified account on first Google sign-in and logs it in', function (): void {
    Event::fake([Registered::class]);
    fakeIdentity('google', 'g-1', 'Jane@Example.com');

    $this->get('/auth/google/callback?code=x&state=y')->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'jane@example.com')->firstOrFail();
    expect($user->username)->toBe('jane_doe')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(SocialAccount::query()->where('provider', 'google')->where('provider_user_id', 'g-1')->value('user_id'))->toBe($user->id);
    $this->assertAuthenticatedAs($user);
    Event::assertDispatched(Registered::class);
});

it('logs a returning identity into the same account, even after an e-mail change at the provider', function (): void {
    $user = User::factory()->create();
    SocialAccount::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-2', 'email' => $user->email]);
    fakeIdentity('google', 'g-2', 'changed@example.com');

    $this->get('/auth/google/callback')->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

it('links an existing account only when the provider verified the e-mail', function (): void {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    fakeIdentity('google', 'g-attacker', 'owner@example.com', verified: false);
    $this->get('/auth/google/callback')->assertRedirect('/en/login')->assertSessionHas('error');
    $this->assertGuest();
    expect(SocialAccount::query()->count())->toBe(0);

    fakeIdentity('google', 'g-owner', 'owner@example.com', verified: true);
    $this->get('/auth/google/callback')->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::query()->where('user_id', $user->id)->value('provider_user_id'))->toBe('g-owner');
});

it('sends accounts with two-step verification to the 2FA challenge', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    SocialAccount::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-3', 'email' => $user->email]);
    fakeIdentity('google', 'g-3', $user->email);

    $this->get('/auth/google/callback')->assertRedirect('/two-factor-challenge')->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

it('refuses suspended accounts and identities without an e-mail', function (): void {
    $user = User::factory()->create(['status' => UserStatus::Suspended]);
    SocialAccount::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-4', 'email' => $user->email]);
    fakeIdentity('google', 'g-4', $user->email);
    $this->get('/auth/google/callback')->assertRedirect('/en/login')->assertSessionHas('error');
    $this->assertGuest();

    fakeIdentity('google', 'g-5', null);
    $this->get('/auth/google/callback')->assertRedirect('/en/login')->assertSessionHas('error');
    expect(User::query()->count())->toBe(1);
});

it('picks a free username when the natural one is taken', function (): void {
    User::factory()->create(['username' => 'jane_doe']);
    fakeIdentity('google', 'g-6', 'jane2@example.com');

    $this->get('/auth/google/callback');
    expect(User::query()->where('email', 'jane2@example.com')->value('username'))->toBe('jane_doe2');
});

it('connects and disconnects an account from Settings › Security', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    SocialAccount::query()->create(['user_id' => $other->id, 'provider' => 'google', 'provider_user_id' => 'g-taken', 'email' => $other->email]);
    $this->actingAs($user);

    // Starting from Settings marks the round trip as "connect".
    $this->get('/auth/google/redirect');
    fakeIdentity('google', 'g-taken', 'someone@example.com');
    $this->get('/auth/google/callback')->assertRedirect('/settings/security#connected')->assertSessionHas('error');

    $this->get('/auth/google/redirect');
    fakeIdentity('google', 'g-mine', 'me@gmail.com');
    $this->get('/auth/google/callback')->assertRedirect('/settings/security#connected')->assertSessionHas('success');
    $this->assertAuthenticatedAs($user);

    $this->get('/settings/security')->assertInertia(fn (Assert $page) => $page->where('social', [['provider' => 'google', 'email' => 'me@gmail.com', 'linked' => true]]));

    $this->from('/settings/security')->delete('/settings/security/social/google')->assertRedirect('/settings/security');
    expect(SocialAccount::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
