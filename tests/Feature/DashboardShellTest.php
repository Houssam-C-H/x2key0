<?php

declare(strict_types=1);

use App\Domains\Affiliate\Actions\AffiliateAccounts;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\SellerProfile;
use App\Shared\Navigation\DashboardMenu;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

it('sends guests to the localized login page', function (): void {
    $this->get('/dashboard', ['Accept-Language' => 'en'])->assertRedirect('/en/login');
    $this->withUnencryptedCookie('locale', 'fr')->get('/buy/orders')->assertRedirect('/fr/login');
});

it('renders the dashboard home with every menu group as a card for sellers', function (): void {
    $this->actingAs(SellerProfile::factory()->create()->user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->has('menu.groups', count(DashboardMenu::groups())));
});

it('shows non-sellers a single "become a seller" entry and no Storage group', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertInertia(function (Assert $page): void {
            $groups = collect($page->toArray()['props']['menu']['groups'])->keyBy('key');

            expect($groups)->toHaveCount(count(DashboardMenu::groups()) - 1)
                ->and($groups->has('storage'))->toBeFalse()
                ->and($groups['seller']['children'])->toHaveCount(1)
                ->and($groups['seller']['children'][0]['href'])->toBe('/seller/onboarding');
        });
});

it('redirects non-sellers from every seller page to onboarding', function (): void {
    $user = User::factory()->create();

    foreach (DashboardMenu::sellerPaths() as $path) {
        $this->actingAs($user)->get($path)->assertRedirect('/seller/onboarding');
    }

    $this->get('/seller/onboarding')->assertOk()->assertInertia(fn (Assert $page) => $page->where('menu.active.key', 'seller.onboarding'));
});

it('matches the spec 1.7 group order', function (): void {
    expect(array_column(DashboardMenu::groups(), 'key'))->toBe([
        'buy', 'seller', 'disputes', 'tickets', 'announcements', 'invoices', 'coupons', 'affiliate', 'storage', 'settings',
    ]);
});

it('renders every sidebar leaf with that leaf active (placeholder until its milestone ships)', function (): void {
    $user = SellerProfile::factory()->create()->user;
    unlockPrivacy($user); // Storage pages sit behind the Privacy Password
    app(AffiliateAccounts::class)->enrol($user); // affiliate pages need an enrolment
    $built = [
        'settings.account', 'settings.security', 'settings.certification', 'seller.store.mine', 'seller.store.services', 'seller.info', 'seller.contacts',
        'buy.orders', 'buy.credits', 'buy.points', 'buy.funding', 'announcements.all', 'announcements.unread', 'announcements.read', 'announcements.starred', 'buy.favorites', 'seller.orders', 'storage.logs', 'seller.offers.create', 'seller.offers', 'storage.groups', 'storage.items', 'storage.recycle',
        'disputes.index', 'disputes.stats', 'seller.violations', 'tickets.all', 'tickets.pending', 'tickets.awaiting', 'tickets.closed', 'seller.withdrawals.records', 'seller.withdrawals.methods', 'invoices.service', 'invoices.vouchers', 'invoices.info',
        'coupons.shopping', 'coupons.platform', 'seller.store.coupons', 'seller.store.coupons_private', 'seller.marketing.emails', 'seller.marketing.records', 'seller.marketing.campaigns',
        'affiliate.manage', 'affiliate.referrals', 'affiliate.videos', 'affiliate.bills', 'affiliate.assets',
        'buy.comments', 'seller.store.comments',
    ];

    foreach (DashboardMenu::leaves() as $leaf) {
        $url = $leaf['path'].(isset($leaf['query']) ? '?'.http_build_query($leaf['query']) : '');

        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($leaf, $built): void {
                $page->where('menu.active.key', $leaf['key']);

                if (! in_array($leaf['key'], $built, true)) {
                    $page->component('Shared/Placeholder')->where('page.milestone', $leaf['milestone']);
                }
            });
    }
});

it('marks query-filtered tabs active and opens their group', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/tickets?status=closed')
        ->assertInertia(function (Assert $page): void {
            $page->where('menu.active.key', 'tickets.closed');
            $groups = collect($page->toArray()['props']['menu']['groups'])->keyBy('key');

            expect($groups['tickets']['open'])->toBeTrue()
                ->and($groups['buy']['open'])->toBeFalse()
                ->and(collect($groups['tickets']['children'])->where('active', true)->pluck('key')->all())->toBe(['tickets.closed']);
        });
});

it('opens nested branches down to the active leaf', function (): void {
    $this->actingAs(SellerProfile::factory()->create()->user)
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
        ->assertInertia(fn (Assert $page) => $page->where('menu.active.key', 'announcements.all'));
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
