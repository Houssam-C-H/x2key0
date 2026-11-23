<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Navigation\DashboardMenu;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

it('sends guests to the localized login page', function (): void {
    $this->get('/dashboard')->assertRedirect('/en/login');
    $this->withUnencryptedCookie('locale', 'fr')->get('/buy/orders')->assertRedirect('/fr/login');
});

it('renders the dashboard home with every menu group as a card', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->has('menu.groups', count(DashboardMenu::groups())));
});

it('matches the spec 1.7 group order', function (): void {
    expect(array_column(DashboardMenu::groups(), 'key'))->toBe([
        'buy', 'seller', 'disputes', 'tickets', 'announcements', 'invoices', 'coupons', 'affiliate', 'storage', 'settings',
    ]);
});

it('renders every sidebar leaf as a placeholder with that leaf active', function (): void {
    $user = User::factory()->create();

    foreach (DashboardMenu::leaves() as $leaf) {
        $url = $leaf['path'].(isset($leaf['query']) ? '?'.http_build_query($leaf['query']) : '');

        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shared/Placeholder')
                ->where('page.key', $leaf['key'])
                ->where('page.milestone', $leaf['milestone']));
    }
});

it('marks query-filtered tabs active and opens their group', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/tickets?status=closed')
        ->assertInertia(function (Assert $page): void {
            $page->where('page.key', 'tickets.closed');
            $groups = collect($page->toArray()['props']['menu']['groups'])->keyBy('key');

            expect($groups['tickets']['open'])->toBeTrue()
                ->and($groups['buy']['open'])->toBeFalse()
                ->and(collect($groups['tickets']['children'])->where('active', true)->pluck('key')->all())->toBe(['tickets.closed']);
        });
});

it('opens nested branches down to the active leaf', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/seller/withdrawals/methods')
        ->assertInertia(function (Assert $page): void {
            $seller = collect($page->toArray()['props']['menu']['groups'])->firstWhere('key', 'seller');
            $branch = collect($seller['children'])->firstWhere('key', 'seller.withdrawals');

            expect($seller['open'])->toBeTrue()
                ->and($branch['open'])->toBeTrue()
                ->and($branch['children'][1]['active'])->toBeTrue();
        });
});

it('falls back to the unfiltered tab for unknown filter values', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/announcements?filter=bogus')
        ->assertInertia(fn (Assert $page) => $page->where('page.key', 'announcements.all'));
});

it('has unique leaf keys and a translation for every label', function (): void {
    $keys = array_column(DashboardMenu::leaves(), 'key');
    expect($keys)->toBe(array_values(array_unique($keys)));

    $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
    $labels = [...array_column(DashboardMenu::groups(), 'label'), ...array_column(DashboardMenu::leaves(), 'label')];

    foreach ($labels as $label) {
        expect($en)->toHaveKey($label);
    }
});

it('registers a named route for every page', function (): void {
    foreach (DashboardMenu::pages() as $leaf) {
        expect(Route::has('dash.'.$leaf['key']))->toBeTrue();
    }
});
