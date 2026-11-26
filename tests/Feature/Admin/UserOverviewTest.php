<?php

declare(strict_types=1);

use App\Domains\Admin\Actions\StaffNotes;
use App\Domains\Admin\Models\StaffNote;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSession;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Shared\Audit\AuditLog;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\Ledger;

/*
 * User 360° page (docs/ADMIN_PLAN.md item 3).
 */

function seededCustomer(): User
{
    $user = User::factory()->create(['username' => 'overview_user', 'phone' => '+33600000000']);
    UserSession::query()->create(['user_id' => $user->id, 'session_id' => 'sess-1', 'ip' => '10.0.0.7', 'user_agent' => 'Firefox on Linux', 'last_active_at' => now()]);
    LoginEvent::query()->create(['user_id' => $user->id, 'identifier' => 'overview_user', 'ip' => '10.0.0.8', 'user_agent' => 'Chrome', 'succeeded' => false, 'created_at' => now()]);
    Ledger::fundCredits($user, 1250);

    return $user;
}

it('shows profile, balances, sessions, sign-ins and notes, but never secrets', function (): void {
    $admin = User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create();
    $user = seededCustomer();
    $user->forceFill(['two_factor_secret' => encrypt('TOPSECRET'), 'two_factor_confirmed_at' => now()])->save();
    app(StaffNotes::class)->add($admin, 'user', $user->id, 'Called the buyer about order 42');
    $this->actingAs($admin);

    Livewire::test(ViewUser::class, ['record' => $user->getRouteKey()])
        ->assertOk()
        ->assertSee('overview_user')
        ->assertSee('USD 12.50')
        ->assertSee('10.0.0.7')
        ->assertSee('10.0.0.8')
        ->assertSee('failed')
        ->assertSee('Called the buyer about order 42')
        ->assertSee('Audit log for this account')
        ->assertDontSee('TOPSECRET');
});

it('suspends, reinstates and resets 2FA with a reason, and signs out one session', function (): void {
    $admin = User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create();
    $user = seededCustomer();
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->actingAs($admin);

    $page = Livewire::test(ViewUser::class, ['record' => $user->getRouteKey()])
        ->callAction(TestAction::make('suspend'), ['reason' => 'Chargeback pattern'])
        ->assertHasNoActionErrors();
    expect($user->refresh()->status)->toBe(UserStatus::Suspended);

    $page->callAction(TestAction::make('reinstate'), ['reason' => 'Bank confirmed'])
        ->callAction(TestAction::make('reset_2fa'), ['reason' => 'ID card checked on video call'])
        ->callAction(TestAction::make('note'), ['body' => 'Watch next orders']);
    expect($user->refresh()->status)->toBe(UserStatus::Active)
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and(StaffNote::query()->where('subject_id', $user->id)->value('body'))->toBe('Watch next orders');

    $session = UserSession::query()->where('user_id', $user->id)->firstOrFail();
    $page->call('revokeSession', $session->id);
    expect($session->refresh()->revoked_at)->not->toBeNull()
        ->and(AuditLog::query()->whereIn('action', ['user.status_changed', 'user.two_factor_reset', 'staff_notes.added', 'user_sessions.revoked'])->count())->toBeGreaterThanOrEqual(4);
});

it('lets support read the page but not change the account', function (): void {
    $user = seededCustomer();
    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create());

    Livewire::test(ViewUser::class, ['record' => $user->getRouteKey()])
        ->assertOk()
        ->assertActionHidden(TestAction::make('suspend'))
        ->assertActionVisible(TestAction::make('note'));

    $session = UserSession::query()->where('user_id', $user->id)->firstOrFail();
    Livewire::test(ViewUser::class, ['record' => $user->getRouteKey()])->call('revokeSession', $session->id)->assertForbidden();
});

it('keeps staff notes append-only', function (): void {
    $note = app(StaffNotes::class)->add(User::factory()->staff(StaffRole::Support)->create(), 'user', 1, 'x');
    expect(fn () => $note->update(['body' => 'y']))->toThrow(LogicException::class)
        ->and(fn () => app(StaffNotes::class)->add(User::factory()->create(), 'user', 1, 'x'))->toThrow(DomainException::class);
});
