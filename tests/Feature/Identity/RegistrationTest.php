<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserPreference;
use App\Domains\Identity\Models\UserSecurity;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(UserLevelSeeder::class);
    Notification::fake();
});

function registration(array $overrides = []): array
{
    return [
        'username' => 'new_buyer',
        'email' => 'New.Buyer@Example.com',
        'password' => 'correct-horse-42',
        'password_confirmation' => 'correct-horse-42',
        'terms' => true,
        ...$overrides,
    ];
}

it('renders the localized sign-up page and keeps a referral code from the link', function (): void {
    $this->get('/fr/register?ref=FRIEND_42')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Register')->where('referralCode', 'FRIEND_42'));
});

it('creates the account with entry level, preferences and security rows, then signs in', function (): void {
    $this->withUnencryptedCookie('currency', 'EUR')
        ->post('/register', registration())
        ->assertRedirect('/dashboard');

    $user = User::query()->where('username', 'new_buyer')->sole();

    expect($user->email)->toBe('new.buyer@example.com')
        ->and($user->public_id)->toHaveLength(26)
        ->and($user->level?->code)->toBe('bronze')
        ->and($user->display_currency)->toBe('EUR')
        ->and(UserPreference::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(UserSecurity::query()->whereKey($user->id)->exists())->toBeTrue();

    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('stores the referral code for later attribution (M15)', function (): void {
    $this->post('/register', registration(['referral_code' => 'FRIEND_42']));

    expect(session('referral_code'))->toBe('FRIEND_42');
});

it('rejects bad usernames', function (string $username): void {
    $this->post('/register', registration(['username' => $username]))->assertSessionHasErrors('username');
    $this->assertGuest();
})->with(['admin', 'x2key', 'ab', 'has space', 'émoji', str_repeat('a', 33)]);

it('rejects duplicate username or email (case-insensitive email)', function (): void {
    User::factory()->create(['username' => 'taken', 'email' => 'taken@example.com']);

    $this->post('/register', registration(['username' => 'taken']))->assertSessionHasErrors('username');
    $this->post('/register', registration(['email' => 'taken@example.com']))->assertSessionHasErrors('email');
});

it('enforces password strength, confirmation and terms', function (array $overrides, string $field): void {
    $this->post('/register', registration($overrides))->assertSessionHasErrors($field);
})->with([
    'too short' => [['password' => 'abc123', 'password_confirmation' => 'abc123'], 'password'],
    'no digits' => [['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'], 'password'],
    'mismatch' => [['password_confirmation' => 'different-42x'], 'password'],
    'terms' => [['terms' => false], 'terms'],
]);
