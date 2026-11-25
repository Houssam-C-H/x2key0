<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\DeleteAccount;
use App\Domains\Identity\Actions\ModerateUser;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Exceptions\AccountDeletionException;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSecurity;
use App\Domains\Identity\Models\UserSession;
use App\Domains\Identity\Notifications\AccountDeletionCode;
use App\Domains\Ledger\Actions\ManualAdjustments;
use App\Domains\Ledger\Actions\VerifyLedger;
use App\Domains\Ledger\Enums\AccountCode;
use App\Domains\Ledger\Exceptions\LedgerException;
use App\Domains\Ledger\Models\LedgerAdjustment;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Reporting\Reports;
use App\Domains\Sellers\Models\SellerProfile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CheckoutFixture;
use Tests\Support\Ledger;

function withPrivacyPassword(User $user, string $password = 'vault-pass-1'): void
{
    UserSecurity::query()->updateOrCreate(['user_id' => $user->id], ['privacy_password_hash' => Hash::make($password), 'privacy_password_set_at' => now()]);
}

function emailCode(User $user): string
{
    $code = '';
    Notification::fake();
    app(DeleteAccount::class)->sendEmailCode($user);
    Notification::assertSentTo($user, AccountDeletionCode::class, function (AccountDeletionCode $n) use (&$code): bool {
        $code = $n->code;

        return true;
    });

    return $code;
}

it('schedules a deletion with two factors, lets the user cancel, and anonymises after the cool-off', function (): void {
    $user = User::factory()->create(['username' => 'leaving_user', 'email' => 'leave@example.com']);
    withPrivacyPassword($user);
    $delete = app(DeleteAccount::class);

    expect(fn () => $delete->request($user, ['privacy' => 'vault-pass-1'], null))->toThrow(AccountDeletionException::class)       // one factor
        ->and(fn () => $delete->request($user, ['privacy' => 'wrong', 'email' => '000000'], null))->toThrow(AccountDeletionException::class);

    $request = $delete->request($user, ['email' => emailCode($user), 'privacy' => 'vault-pass-1'], 'Too many emails');
    expect($request->status)->toBe('scheduled')->and($request->scheduled_for->isAfter(now()->addDays(13)))->toBeTrue()
        ->and($user->refresh()->status)->toBe(UserStatus::Active); // still able to log in and cancel

    $this->actingAs($user)->get('/settings/delete-account')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Settings/DeleteAccount')->where('scheduled.at', fn ($at) => is_string($at)));
    $this->post('/settings/delete-account/cancel')->assertSessionHas('success');
    expect($request->refresh()->status)->toBe('cancelled');

    $delete->request($user, ['email' => emailCode($user), 'privacy' => 'vault-pass-1'], null);
    UserSession::query()->create(['user_id' => $user->id, 'session_id' => 'abc', 'ip' => '1.1.1.1', 'user_agent' => 'x', 'last_active_at' => now()]);
    $this->travel(15)->days();
    expect($delete->processDue())->toBe(['completed' => 1, 'blocked' => 0]);

    $user->refresh();
    expect($user->status)->toBe(UserStatus::Deleted)
        ->and($user->username)->toBe('deleted_'.$user->id)
        ->and($user->email)->not->toContain('leave@')
        ->and($user->name)->toBe('Deleted user')
        ->and(UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('blocks deletion while money, orders or withdrawals are open, and re-checks when the cool-off ends', function (): void {
    $fx = CheckoutFixture::build();
    $buyer = $fx['buyer'];
    withPrivacyPassword($buyer);
    $delete = app(DeleteAccount::class);

    Ledger::fundCredits($buyer, 500);
    $blocked = $delete->request($buyer, ['email' => emailCode($buyer), 'privacy' => 'vault-pass-1'], null);
    expect($blocked->status)->toBe('blocked')->and($blocked->blockers)->toBe(['account.delete.blocker.balance']);

    // A paid open order blocks too.
    $this->actingAs($buyer)->post('/checkout', CheckoutFixture::form($fx['manual'], ['use_credits' => false]));
    CheckoutFixture::webhook(CheckoutFixture::lastOrder($buyer), PaymentStatus::Captured);
    expect($delete->blockers($buyer))->toContain('account.delete.blocker.orders');

    // Scheduled while clean, then an order is placed during the cool-off → blocked at the end.
    $other = User::factory()->create(['created_at' => now()->subMonth()]);
    withPrivacyPassword($other);
    $delete->request($other, ['email' => emailCode($other), 'privacy' => 'vault-pass-1'], null);
    $this->actingAs($other)->post('/checkout', CheckoutFixture::form($fx['manual']));
    CheckoutFixture::webhook(CheckoutFixture::lastOrder($other), PaymentStatus::Captured);
    $this->travel(15)->days();
    expect($delete->processDue())->toBe(['completed' => 0, 'blocked' => 1])
        ->and($other->refresh()->status)->toBe(UserStatus::Active);
});

it('closes the store of a seller whose account is deleted', function (): void {
    $profile = SellerProfile::factory()->create();
    withPrivacyPassword($profile->user);
    app(DeleteAccount::class)->request($profile->user, ['email' => emailCode($profile->user), 'privacy' => 'vault-pass-1'], null);
    $this->travel(15)->days();
    app(DeleteAccount::class)->processDue();

    expect($profile->refresh()->status->value)->toBe('banned');
});

it('posts small manual adjustments at once and holds large ones for a second finance user', function (): void {
    $alice = User::factory()->staff(StaffRole::Finance)->create();
    $bob = User::factory()->staff(StaffRole::Finance)->create();
    $customer = User::factory()->create();
    $adjust = app(ManualAdjustments::class);

    $small = $adjust->request($alice, AccountCode::BuyerCredits, $customer->id, AccountCode::ExpPromotions, 500, 'Goodwill credit for ticket #12');
    expect($small->status)->toBe('posted')->and(Ledger::credits($customer))->toBe(500);

    $big = $adjust->request($alice, AccountCode::BuyerCredits, $customer->id, AccountCode::ExpPromotions, 15000, 'Compensation agreed by management');
    expect($big->status)->toBe('pending')->and(Ledger::credits($customer))->toBe(500)
        ->and(fn () => $adjust->approve($big, $alice))->toThrow(LedgerException::class);

    $adjust->approve($big, $bob);
    expect($big->refresh()->status)->toBe('posted')->and($big->approved_by)->toBe($bob->id)
        ->and(Ledger::credits($customer))->toBe(15500)
        ->and(app(VerifyLedger::class)->handle())->toBe([]);

    $rejected = $adjust->request($alice, AccountCode::BuyerCredits, $customer->id, AccountCode::ExpPromotions, -20000, 'Clawback of a duplicate refund');
    $adjust->reject($rejected, $bob, 'Not a duplicate');
    expect($rejected->refresh()->status)->toBe('rejected')->and(Ledger::credits($customer))->toBe(15500);
});

it('refuses adjustments from non-finance staff, without a reason, or with a user account but no user', function (): void {
    $adjust = app(ManualAdjustments::class);
    $finance = User::factory()->staff(StaffRole::Finance)->create();

    expect(fn () => $adjust->request(User::factory()->staff(StaffRole::Support)->create(), AccountCode::ExpPromotions, null, AccountCode::Bank, 100, 'Valid reason here'))->toThrow(LedgerException::class)
        ->and(fn () => $adjust->request($finance, AccountCode::BuyerCredits, 1, AccountCode::ExpPromotions, 100, 'short'))->toThrow(LedgerException::class)
        ->and(fn () => $adjust->request($finance, AccountCode::BuyerCredits, null, AccountCode::ExpPromotions, 100, 'Valid reason here'))->toThrow(LedgerException::class)
        ->and(LedgerAdjustment::query()->count())->toBe(0);
});

it('lets staff suspend, ban, reactivate, reset 2FA and sign users out, never themselves', function (): void {
    $admin = User::factory()->staff(StaffRole::Admin)->create();
    $user = User::factory()->withTwoFactor()->create();
    UserSession::query()->create(['user_id' => $user->id, 'session_id' => 'abc', 'ip' => '1.1.1.1', 'user_agent' => 'x', 'last_active_at' => now()]);
    $moderate = app(ModerateUser::class);

    $moderate->setStatus($user, UserStatus::Suspended, $admin, 'Chargeback fraud investigation');
    expect($user->refresh()->status)->toBe(UserStatus::Suspended)
        ->and(UserSession::query()->whereNull('revoked_at')->count())->toBe(0);

    $moderate->setStatus($user, UserStatus::Active, $admin, 'Cleared');
    $moderate->resetTwoFactor($user, $admin, 'Identity checked by video call');
    expect($user->refresh()->two_factor_confirmed_at)->toBeNull()
        ->and(fn () => $moderate->setStatus($admin, UserStatus::Banned, $admin, 'x'))->toThrow(DomainException::class);
});

it('reports GMV, take rate, refunds and queues for a period', function (): void {
    $fx = CheckoutFixture::build();
    $this->actingAs($fx['buyer']);
    $order = CheckoutFixture::deliveredOrder($fx);

    $r = app(Reports::class)->summary(now()->subDay(), now()->addDay());
    expect($r['orders'])->toBe(1)
        ->and($r['gmv'])->toBe($order->total_amount)
        ->and($r['revenue'])->toBe($order->commission_amount + $order->fees())
        ->and($r['takeRateBps'])->toBe(intdiv(($order->commission_amount + $order->fees()) * 10000, $order->total_amount))
        ->and($r['byType'])->toHaveCount(1)
        ->and($r['topSellers'][0]['name'])->toBe('Pixel Vault')
        ->and($r['queues'])->toHaveKey('payment_review');
    expect($order->status)->toBe(OrderStatus::Delivered);
});

it('opens the new admin screens to the right staff', function (): void {
    $finance = User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create();
    $this->actingAs($finance)->get('/admin/reports')->assertOk();
    $this->get('/admin/ledger-adjustments')->assertOk();

    $support = User::factory()->staff(StaffRole::Support)->withTwoFactor()->create();
    $this->actingAs($support)->get('/admin/account-deletions')->assertOk();
    $this->get('/admin/reports')->assertForbidden();
    $this->get('/admin/ledger-adjustments')->assertForbidden();
});
