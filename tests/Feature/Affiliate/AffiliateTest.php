<?php

declare(strict_types=1);

use App\Domains\Affiliate\Actions\AffiliateAccounts;
use App\Domains\Affiliate\Actions\AffiliateCommissions;
use App\Domains\Affiliate\Actions\AffiliateVideos;
use App\Domains\Affiliate\Actions\TrackReferrals;
use App\Domains\Affiliate\Exceptions\AffiliateException;
use App\Domains\Affiliate\Models\Affiliate;
use App\Domains\Affiliate\Models\AffiliateCommission;
use App\Domains\Affiliate\Models\AffiliateReferral;
use App\Domains\Affiliate\Models\AffiliateVideoRecord;
use App\Domains\Affiliate\Support\AffiliatePresenter;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Ledger\Data\AccountRef;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Support\Accounts;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

function affiliateSignup(array $overrides = []): array
{
    return [
        'username' => 'invited_one',
        'email' => 'invited@example.com',
        'password' => 'correct-horse-42',
        'password_confirmation' => 'correct-horse-42',
        'terms' => true,
        ...$overrides,
    ];
}

function affiliateOf(User $user): Affiliate
{
    return app(AffiliateAccounts::class)->enrol($user);
}

function pendingOf(User $user): int
{
    return Accounts::owed(AccountRef::user(AccountCode::AffiliatePending, $user->id, 'USD'));
}

function availableOf(User $user): int
{
    return Accounts::owed(AccountRef::user(AccountCode::AffiliateAvailable, $user->id, 'USD'));
}

/** A referred buyer completes one order of the checkout fixture. */
function completedReferredOrder(array $fx, User $buyer): Order
{
    test()->actingAs($buyer);
    $order = CheckoutFixture::deliveredOrder([...$fx, 'buyer' => $buyer]);

    return app(OrderStateMachine::class)->complete($order, ActorType::Buyer, $buyer);
}

function refer(Affiliate $affiliate, User $user, int $days = 90): AffiliateReferral
{
    $r = new AffiliateReferral;
    $r->forceFill(['affiliate_id' => $affiliate->id, 'referred_user_id' => $user->id, 'signed_up_at' => now(), 'attribution_expires_at' => now()->addDays($days)])->save();
    $user->forceFill(['referred_by_affiliate_id' => $affiliate->id])->save();

    return $r;
}

it('enrols once, with a code, a custom code and promotional links to this site only', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/affiliate/manage')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Affiliate/Manage')->where('affiliate', null));

    $this->post('/affiliate/enrol')->assertRedirect('/affiliate/manage');
    $this->post('/affiliate/enrol');
    expect(Affiliate::query()->count())->toBe(1);

    $this->post('/affiliate/code', ['code' => 'gamer42'])->assertSessionHas('success');
    $this->post('/affiliate/code', ['code' => 'a!'])->assertSessionHasErrors('code');
    expect(Affiliate::query()->sole()->code)->toBe('GAMER42');

    $this->post('/affiliate/links', ['target' => '/en/games'])->assertSessionHas('success');
    $this->post('/affiliate/links', ['target' => 'https://evil.example/phish'])->assertSessionHasErrors('target');
    $this->post('/affiliate/links', ['target' => '//evil.example'])->assertSessionHasErrors('target');

    $this->get('/affiliate/manage')->assertInertia(fn (Assert $p) => $p
        ->where('affiliate.code', 'GAMER42')
        ->where('affiliate.link', url('/r/GAMER42'))
        ->has('affiliate.links', 1)
        ->where('affiliate.links.0.target', '/en/games'));
});

it('counts clicks, keeps the first click in a cookie and sends visitors on', function (): void {
    $affiliate = affiliateOf(User::factory()->create());
    $link = app(AffiliateAccounts::class)->createLink($affiliate, '/en/games');

    $first = $this->get("/r/{$affiliate->code}/{$link->slug}")->assertRedirect('/en/games')->assertCookie(TrackReferrals::COOKIE);
    expect(TrackReferrals::parse($first->getCookie(TrackReferrals::COOKIE)?->getValue()))->toMatchArray([0 => $affiliate->id, 1 => $link->id]);

    // A later click from the same browser counts but does not replace the cookie.
    $other = affiliateOf(User::factory()->create());
    $this->withCookie(TrackReferrals::COOKIE, "{$affiliate->id}.{$link->id}.".now()->getTimestamp())
        ->get("/r/{$other->code}")->assertRedirect()->assertCookieMissing(TrackReferrals::COOKIE);

    $this->get('/r/NOSUCHCODE')->assertRedirect('/en');
    expect($affiliate->refresh()->clicks)->toBe(1)->and($link->refresh()->clicks)->toBe(1)->and($other->refresh()->clicks)->toBe(1);
});

it('attributes a sign-up to the clicked affiliate, or to a typed code, and blocks self-referral from the same device', function (): void {
    $affiliate = affiliateOf(User::factory()->create());

    $this->withCookie(TrackReferrals::COOKIE, "{$affiliate->id}.0.".now()->getTimestamp())->post('/register', affiliateSignup());
    $invited = User::query()->where('username', 'invited_one')->sole();
    expect($invited->referred_by_affiliate_id)->toBe($affiliate->id)
        ->and(AffiliateReferral::query()->sole()->attribution_expires_at->isAfter(now()->addDays(89)))->toBeTrue()
        ->and($affiliate->refresh()->signups)->toBe(1);

    // Typed code, no click.
    auth()->logout();
    $this->post('/register', affiliateSignup(['username' => 'typed_one', 'email' => 'typed@example.com', 'referral_code' => strtolower($affiliate->code)]));
    expect(User::query()->where('username', 'typed_one')->sole()->referred_by_affiliate_id)->toBe($affiliate->id);

    // Same browser as the affiliate's own sign-ins → no referral.
    auth()->logout();
    UserDevice::query()->create(['user_id' => $affiliate->user_id, 'fingerprint' => hash('sha256', str_repeat('d', 42)), 'name' => 'Chrome']);
    $this->withCookie('x2k_device', str_repeat('d', 42))->withCookie(TrackReferrals::COOKIE, "{$affiliate->id}.0.".now()->getTimestamp())
        ->post('/register', affiliateSignup(['username' => 'sock_puppet', 'email' => 'sock@example.com']));
    expect(User::query()->where('username', 'sock_puppet')->sole()->referred_by_affiliate_id)->toBeNull();
});

it('earns level-1 and level-2 commission on the platform net when a referred buyer completes an order, once', function (): void {
    $fx = CheckoutFixture::build();
    $top = affiliateOf(User::factory()->create());
    $inviterUser = User::factory()->create();
    refer($top, $inviterUser);
    $inviter = affiliateOf($inviterUser->refresh());
    expect($inviter->parent_affiliate_id)->toBe($top->id);

    refer($inviter, $fx['buyer']);
    $order = completedReferredOrder($fx, $fx['buyer']);

    $base = AffiliateCommissions::base($order);
    expect($base)->toBe($order->commission_amount + $order->fees())
        ->and(pendingOf($inviter->user))->toBe((int) round($base * 0.20))
        ->and(pendingOf($top->user))->toBe((int) round($base * 0.03))
        ->and($order->refresh()->affiliate_id)->toBe($inviter->id);

    // The listener already ran; running again changes nothing.
    expect(app(AffiliateCommissions::class)->accrue($order))->toBe(0)
        ->and(AffiliateCommission::query()->count())->toBe(2)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('pays nothing after the attribution window, for suspended affiliates, or when the affiliate is the seller', function (): void {
    $fx = CheckoutFixture::build();

    $late = affiliateOf(User::factory()->create());
    refer($late, $fx['buyer'], days: 1);
    $this->travel(2)->days();
    completedReferredOrder($fx, $fx['buyer']);
    expect(AffiliateCommission::query()->count())->toBe(0);

    // The seller invites a buyer to buy from their own store.
    $buyer2 = User::factory()->create(['created_at' => now()->subMonth()]);
    refer(affiliateOf($fx['seller']->user), $buyer2);
    completedReferredOrder($fx, $buyer2);
    expect(AffiliateCommission::query()->count())->toBe(0);

    $buyer3 = User::factory()->create(['created_at' => now()->subMonth()]);
    $suspended = affiliateOf(User::factory()->create());
    $suspended->forceFill(['status' => Affiliate::SUSPENDED])->save();
    refer($suspended, $buyer3);
    completedReferredOrder($fx, $buyer3);
    expect(AffiliateCommission::query()->count())->toBe(0);
});

it('releases commissions after the seller hold + 7 days, and transfers to the Credits Balance from USD 10', function (): void {
    $fx = CheckoutFixture::build();
    $affiliate = affiliateOf(User::factory()->create());
    refer($affiliate, $fx['buyer']);
    completedReferredOrder($fx, $fx['buyer']);
    $earned = pendingOf($affiliate->user);

    $this->artisan('affiliate:release-commissions')->assertSuccessful();
    expect(availableOf($affiliate->user))->toBe(0);

    $this->travel(60)->days();
    $this->artisan('affiliate:release-commissions')->assertSuccessful();
    expect(pendingOf($affiliate->user))->toBe(0)->and(availableOf($affiliate->user))->toBe($earned)
        ->and(AffiliateCommission::query()->sole()->status)->toBe('approved');

    // Top up the available balance with a video reward so it reaches the USD 10 minimum.
    $video = app(AffiliateVideos::class)->submit($affiliate, 'https://www.youtube.com/watch?v=abc', 1200);
    app(AffiliateVideos::class)->approve($video, 1500, User::factory()->staff(StaffRole::MerchantManager)->create());
    $available = availableOf($affiliate->user);

    $accounts = app(AffiliateAccounts::class);
    expect(fn () => $accounts->transferToCredits($affiliate, 999, 'k1'))->toThrow(AffiliateException::class)
        ->and(fn () => $accounts->transferToCredits($affiliate, $available + 1, 'k2'))->toThrow(AffiliateException::class);

    $accounts->transferToCredits($affiliate, 1000, 'k3');
    $accounts->transferToCredits($affiliate, 1000, 'k3'); // same key: once
    expect(availableOf($affiliate->user))->toBe($available - 1000)
        ->and(Ledger::credits($affiliate->user))->toBe(1000)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('asks for a recent password before transferring', function (): void {
    $user = User::factory()->create();
    affiliateOf($user);

    $this->actingAs($user)->post('/affiliate/transfer', ['amount' => 10, 'idempotency_key' => strtolower((string) Str::ulid())])->assertRedirect();
    expect(Ledger::credits($user))->toBe(0);
});

it('reverses commission when a completed order is partly refunded, and all of it on a lost chargeback', function (): void {
    $fx = CheckoutFixture::build();
    $affiliate = affiliateOf(User::factory()->create());
    refer($affiliate, $fx['buyer']);
    $order = completedReferredOrder($fx, $fx['buyer']);
    $commission = AffiliateCommission::query()->sole();

    // Half of the platform's net goes back with a refund → half of the commission is reversed.
    $order->forceFill(['commission_amount' => intdiv($order->commission_amount, 2), 'refunded_fee_amount' => $order->fees()])->save();
    app(AffiliateCommissions::class)->recalculate($order->refresh());
    $expected = (int) round(AffiliateCommissions::base($order) * 0.20);
    expect($commission->refresh()->net())->toBe($expected)->and(pendingOf($affiliate->user))->toBe($expected);

    app(AffiliateCommissions::class)->reverseAll($order);
    expect($commission->refresh()->status)->toBe('reversed')->and(pendingOf($affiliate->user))->toBe(0)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);
});

it('takes video links, detects the platform, and rewards approved videos into the available balance', function (): void {
    $affiliate = affiliateOf(User::factory()->create());
    $this->actingAs($affiliate->user);

    $this->post('/affiliate/videos', ['url' => 'https://www.tiktok.com/@me/video/1', 'views' => 5000])->assertSessionHas('success');
    $this->post('/affiliate/videos', ['url' => 'https://www.tiktok.com/@me/video/1'])->assertSessionHasErrors('url');
    $this->post('/affiliate/videos', ['url' => 'http://insecure.example/v'])->assertSessionHasErrors('url');

    $video = AffiliateVideoRecord::query()->sole();
    expect($video->platform)->toBe('tiktok');

    $staff = User::factory()->staff(StaffRole::MerchantManager)->create();
    expect(fn () => app(AffiliateVideos::class)->reject($video, '', $staff))->toThrow(AffiliateException::class);
    app(AffiliateVideos::class)->approve($video, 2500, $staff);
    expect(fn () => app(AffiliateVideos::class)->approve($video->refresh(), 2500, $staff))->toThrow(AffiliateException::class)
        ->and(availableOf($affiliate->user))->toBe(2500);

    $this->get('/affiliate/videos')->assertInertia(fn (Assert $p) => $p->where('records.0.status', 'approved')->where('records.0.reward', 2500));
});

it('shows masked invitees, the Alliance Bill from the ledger, and keeps non-affiliates on Manage', function (): void {
    $fx = CheckoutFixture::build();
    $affiliate = affiliateOf(User::factory()->create());
    $fx['buyer']->forceFill(['name' => 'Samira'])->save();
    refer($affiliate, $fx['buyer']);
    $order = completedReferredOrder($fx, $fx['buyer']);

    $this->actingAs($affiliate->user)->get('/affiliate/referrals')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('rows.0.member', 'S****a')
        ->where('rows.0.orders', 1)
        ->where('rows.0.commission', pendingOf($affiliate->user)));

    $this->get('/affiliate/bills')->assertInertia(fn (Assert $p) => $p
        ->has('rows', 1)
        ->where('rows.0.type', 'direct')
        ->where('rows.0.order', $order->number)
        ->where('totals.net', pendingOf($affiliate->user)));

    expect(AffiliatePresenter::mask('Al'))->toBe('A*');

    $this->actingAs(User::factory()->create())->get('/affiliate/bills')->assertRedirect('/affiliate/manage');
});

it('shows the public landing page and opens the affiliate admin to merchant managers', function (): void {
    $this->get('/en/affiliate')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Public/Affiliate')->where('rates.direct', 2000)->where('enrolled', false));

    $this->actingAs(User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create());
    foreach (['/admin/affiliates', '/admin/affiliate-videos', '/admin/creative-assets', '/admin/creative-assets/create'] as $url) {
        $this->get($url)->assertOk();
    }
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create())->get('/admin/affiliates')->assertForbidden();
});
