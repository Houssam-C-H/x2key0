<?php

declare(strict_types=1);

use App\Domains\Admin\Actions\ChangePolicySetting;
use App\Domains\Admin\Models\SettingChange;
use App\Domains\Admin\Support\PolicyCatalog;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Filament\Pages\PlatformSettings;
use App\Shared\Audit\AuditLog;
use App\Shared\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * Platform settings screen (docs/ADMIN_PLAN.md item 1).
 */

it('labels every Part 27 policy key', function (): void {
    expect(array_keys(PolicyCatalog::all()))->toEqualCanonicalizing(array_keys((array) config('x2key.policy')));
});

it('converts money and rates between the form and integer minor units without floats', function (): void {
    expect(PolicyCatalog::toInput('commission_bps', 800))->toBe('8.00')
        ->and(PolicyCatalog::fromInput('commission_bps', '8.5'))->toBe(850)
        ->and(PolicyCatalog::toInput('withdrawal_fee_amount', 5))->toBe('0.05')
        ->and(PolicyCatalog::fromInput('withdrawal_fee_amount', '0.05'))->toBe(5)
        ->and(PolicyCatalog::fromInput('marketing_email_unit_price_millicents', '0.001'))->toBe(100)
        ->and(PolicyCatalog::fromInput('commission_bps', '8.123'))->toBe('8.123') // rejected later by normalise
        ->and(fn () => PolicyCatalog::normalise('commission_bps', '8.123'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PolicyCatalog::normalise('payment_window_minutes', -1))->toThrow(InvalidArgumentException::class)
        ->and(PolicyCatalog::display('commission_bps', 800))->toBe('8.00 %');
});

it('applies an ordinary setting at once with its reason in the audit log', function (): void {
    $admin = User::factory()->staff(StaffRole::Admin)->create();

    $change = app(ChangePolicySetting::class)->request($admin, 'payment_window_minutes', 45, 'Buyers asked for more time');

    expect($change->status)->toBe(SettingChange::APPLIED)
        ->and(app(Settings::class)->int('payment_window_minutes'))->toBe(45)
        ->and(AuditLog::query()->where('action', 'settings.updated')->latest('id')->value('after'))->toMatchArray(['key' => 'payment_window_minutes', 'value' => 45, 'reason' => 'Buyers asked for more time']);
});

it('holds a money setting until another admin approves it', function (): void {
    $alice = User::factory()->staff(StaffRole::Admin)->create();
    $bob = User::factory()->staff(StaffRole::Admin)->create();
    $act = app(ChangePolicySetting::class);

    $change = $act->request($alice, 'commission_bps', 900, 'New pricing from December');
    expect($change->status)->toBe(SettingChange::PENDING)
        ->and(app(Settings::class)->int('commission_bps'))->toBe(800)
        ->and(fn () => $act->approve($change, $alice))->toThrow(DomainException::class)
        ->and(fn () => $act->request($bob, 'commission_bps', 950, 'Second change in a row'))->toThrow(DomainException::class);

    $act->approve($change, $bob);
    expect($change->refresh()->status)->toBe(SettingChange::APPLIED)
        ->and($change->decided_by)->toBe($bob->id)
        ->and(app(Settings::class)->int('commission_bps'))->toBe(900);

    // Reset goes back to the default, also through approval.
    $reset = $act->request($bob, 'commission_bps', null, 'Back to launch pricing', reset: true);
    $act->reject($reset, $alice, 'Not yet');
    expect($reset->refresh()->status)->toBe(SettingChange::REJECTED)->and(app(Settings::class)->int('commission_bps'))->toBe(900);
});

it('refuses non-admins, missing reasons, bad values and empty changes', function (): void {
    $finance = User::factory()->staff(StaffRole::Finance)->create();
    $admin = User::factory()->staff(StaffRole::Admin)->create();
    $act = app(ChangePolicySetting::class);

    expect(fn () => $act->request($finance, 'payment_window_minutes', 40, 'Reason here'))->toThrow(DomainException::class)
        ->and(fn () => $act->request($admin, 'payment_window_minutes', 40, ''))->toThrow(DomainException::class)
        ->and(fn () => $act->request($admin, 'payment_window_minutes', 'abc', 'Reason here'))->toThrow(DomainException::class)
        ->and(fn () => $act->request($admin, 'payment_window_minutes', 30, 'Reason here'))->toThrow(DomainException::class)
        ->and(fn () => $act->request($admin, 'payment_window_minutes', null, 'Reason here', reset: true))->toThrow(DomainException::class);
});

it('saves changed values from the screen and lets a second admin approve from the table', function (): void {
    $alice = User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create();
    $this->actingAs($alice);

    Livewire::test(PlatformSettings::class)
        ->assertOk()
        ->assertSee('Seller commission')
        ->assertSee('Default: 8.00 %')
        ->fillForm(['payment_window_minutes' => '40', 'commission_bps' => '9.25', 'settlement_hold_days.accounts' => '21', 'reason' => 'Quarterly review'])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(Settings::class);
    $settings->flush();
    expect($settings->int('payment_window_minutes'))->toBe(40)
        ->and($settings->int('commission_bps'))->toBe(800)
        ->and(SettingChange::query()->where('status', 'pending')->pluck('key')->sort()->values()->all())->toBe(['commission_bps', 'settlement_hold_days']);

    $bob = User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create();
    $this->actingAs($bob);
    $pending = SettingChange::query()->where('key', 'settlement_hold_days')->firstOrFail();
    Livewire::test(PlatformSettings::class)->callAction(TestAction::make('approve')->table($pending));

    $settings->flush();
    expect($settings->int('settlement_hold_days.accounts'))->toBe(21)
        ->and($settings->int('settlement_hold_days.top_up'))->toBe(3);
});

it('is open to admins only', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());
    expect(PlatformSettings::canAccess())->toBeFalse();
    $this->get('/admin/platform-settings')->assertForbidden();
});
