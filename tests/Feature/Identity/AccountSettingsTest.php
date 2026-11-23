<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserMessenger;
use Inertia\Testing\AssertableInertia as Assert;

function accountPayload(array $overrides = []): array
{
    return [
        'name' => 'Player',
        'locale' => 'fr',
        'display_currency' => 'MAD',
        'country' => 'ma',
        'timezone' => 'Africa/Casablanca',
        'seller_promo_emails' => false,
        'messengers' => ['telegram' => '@player', 'whatsapp' => ''],
        ...$overrides,
    ];
}

it('shows the account page', function (): void {
    $user = User::factory()->create(['username' => 'shown_user']);

    $this->actingAs($user)->get('/settings/account')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Account')
            ->where('account.username', 'shown_user')
            ->has('messengers', 5));
});

it('updates profile, preferences and messengers, and sets locale/currency cookies', function (): void {
    $user = User::factory()->create();
    UserMessenger::query()->create(['user_id' => $user->id, 'type' => 'whatsapp', 'handle' => '+212600']);

    $this->actingAs($user)->put('/settings/account', accountPayload())
        ->assertSessionHasNoErrors()
        ->assertCookie('locale', 'fr', encrypted: false)
        ->assertCookie('currency', 'MAD', encrypted: false);

    $user->refresh()->load('messengers', 'preferences');

    expect($user->locale)->toBe('fr')
        ->and($user->country)->toBe('MA')
        ->and($user->timezone)->toBe('Africa/Casablanca')
        ->and($user->preferences?->seller_promo_emails)->toBeFalse()
        ->and($user->messengers->pluck('handle', 'type.value')->all())->toBe(['telegram' => '@player']);
});

it('validates account fields', function (array $overrides, string $field): void {
    $this->actingAs(User::factory()->create())
        ->put('/settings/account', accountPayload($overrides))
        ->assertSessionHasErrors($field);
})->with([
    [['locale' => 'de'], 'locale'],
    [['display_currency' => 'BTC'], 'display_currency'],
    [['timezone' => 'Mars/Base'], 'timezone'],
    [['country' => 'MAR'], 'country'],
    [['name' => ''], 'name'],
]);

it('ignores unknown messenger types and cannot touch username or email', function (): void {
    $user = User::factory()->create(['username' => 'fixed_name', 'email' => 'fixed@example.com']);

    $this->actingAs($user)->put('/settings/account', accountPayload([
        'messengers' => ['icq' => '123'],
        'username' => 'hacked',
        'email' => 'hacked@example.com',
    ]))->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->username)->toBe('fixed_name')
        ->and($user->email)->toBe('fixed@example.com')
        ->and(UserMessenger::query()->where('user_id', $user->id)->count())->toBe(0);
});
