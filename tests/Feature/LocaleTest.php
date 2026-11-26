<?php

declare(strict_types=1);

use App\Http\Controllers\PreferencesController;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects the bare domain to the browser language', function (string $header, string $expected): void {
    $this->get('/', ['Accept-Language' => $header])->assertRedirect("/{$expected}");
})->with([
    ['fr-FR,fr;q=0.9,en;q=0.8', 'fr'],
    ['ar-MA,ar;q=0.9', 'ar'],
    ['de-DE,de;q=0.9', 'de'],
    ['it-IT,it;q=0.9', 'en'],
]);

it('prefers the locale cookie over the browser language', function (): void {
    $this->withUnencryptedCookie('locale', 'ar')
        ->get('/', ['Accept-Language' => 'fr-FR'])
        ->assertRedirect('/ar');
});

it('renders the home page in each locale with the right direction', function (string $locale, string $dir): void {
    $response = $this->get("/{$locale}");

    $response->assertOk()
        ->assertSee("dir=\"{$dir}\"", false)
        ->assertSee("lang=\"{$locale}\"", false)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Home')
            ->where('locale', $locale)
            ->where('dir', $dir)
            ->has('categoryTypes', 15)
            // Translation keys contain dots, so read the map directly instead of a dot-path.
            ->where('translations', fn ($t): bool => $t['header.become_seller'] === __('header.become_seller', locale: $locale)));

    $response->assertCookie('locale', $locale, encrypted: false);
})->with([['en', 'ltr'], ['fr', 'ltr'], ['ar', 'rtl']]);

it('translates category labels server-side', function (): void {
    $this->get('/fr')->assertInertia(fn (Assert $page) => $page
        ->where('categoryTypes.0', ['code' => 'currency', 'label' => 'Monnaie', 'hero' => true]));
});

it('returns 404 for unsupported locales', function (): void {
    $this->get('/it')->assertNotFound();
});

it('saves locale and currency preferences and swaps the locale in the path', function (): void {
    $this->post('/preferences', ['locale' => 'ar', 'currency' => 'EUR', 'redirect' => '/fr/login'])
        ->assertRedirect('/ar/login')
        ->assertCookie('locale', 'ar', encrypted: false)
        ->assertCookie('currency', 'EUR', encrypted: false);
});

it('shares the display currency from the cookie', function (): void {
    $this->withUnencryptedCookie('currency', 'MAD')
        ->get('/en')
        ->assertInertia(fn (Assert $page) => $page->where('currency', 'MAD'));
});

it('ignores unknown currencies', function (): void {
    $this->withUnencryptedCookie('currency', 'XXX')
        ->get('/en')
        ->assertInertia(fn (Assert $page) => $page->where('currency', 'USD'));
});

it('validates preferences', function (): void {
    $this->post('/preferences', ['locale' => 'xx', 'currency' => 'EUR'])->assertSessionHasErrors('locale');
    $this->post('/preferences', ['locale' => 'en', 'currency' => 'BTC'])->assertSessionHasErrors('currency');
});

it('never redirects off-site', function (string $redirect, string $expected): void {
    expect(PreferencesController::localizedPath($redirect, 'fr'))->toBe($expected);
})->with([
    ['https://evil.example', '/fr'],
    ['//evil.example/x', '/fr'],
    ['/en', '/fr'],
    ['/en/login', '/fr/login'],
    ['/', '/fr'],
    ['/buy/orders?x=1', '/buy/orders?x=1'],
]);
