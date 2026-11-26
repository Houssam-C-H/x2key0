<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Payments\Models\PaymentMethod;
use App\Domains\Payments\Support\PaymentRouter;
use App\Filament\Resources\Payments\Pages\EditPaymentMethod;
use App\Filament\Resources\Payments\Pages\ListPaymentMethods;
use App\Filament\Resources\Payments\PaymentMethodResource;
use App\Filament\Support\MinorUnits;
use App\Shared\Audit\AuditLog;
use Database\Seeders\PaymentMethodSeeder;
use Livewire\Livewire;

/*
 * Payment methods catalog (docs/ADMIN_PLAN.md item 9).
 */

beforeEach(fn () => (new PaymentMethodSeeder)->run());

it('edits countries, limits and fees with money typed as decimals, audited', function (): void {
    $method = PaymentMethod::query()->orderBy('sort')->firstOrFail();
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());

    Livewire::test(EditPaymentMethod::class, ['record' => $method->getRouteKey()])
        ->assertSchemaStateSet(['min_amount' => MinorUnits::toDecimal($method->min_amount)])
        ->fillForm(['countries' => ['fr', 'BE'], 'min_amount' => '2.50', 'max_amount' => '', 'fee_bps' => 150, 'fee_fixed' => '0.30'])
        ->call('save')
        ->assertHasNoFormErrors();

    $method->refresh();
    expect($method->countries)->toBe(['FR', 'BE'])
        ->and($method->min_amount)->toBe(250)
        ->and($method->max_amount)->toBeNull()
        ->and($method->fee_bps)->toBe(150)
        ->and($method->fee_fixed)->toBe(30)
        ->and(AuditLog::query()->where('subject_type', $method->getMorphClass())->where('subject_id', $method->id)->exists())->toBeTrue();
});

it('switches a method off for checkout from the list', function (): void {
    $method = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create());

    Livewire::test(ListPaymentMethods::class)->call('updateTableColumnState', 'is_enabled', (string) $method->getKey(), false);

    expect($method->refresh()->is_enabled)->toBeFalse()
        ->and(collect(app(PaymentRouter::class)->available(null, 'USD', 1000))->pluck('code'))->not->toContain($method->code);
});

it('converts decimals exactly and keeps support out', function (): void {
    expect(MinorUnits::fromDecimal('10.5'))->toBe(1050)->and(MinorUnits::fromDecimal('0.07'))->toBe(7)
        ->and(MinorUnits::fromDecimal('1.234'))->toBeNull()->and(MinorUnits::toDecimal(5))->toBe('0.05');

    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create());
    expect(PaymentMethodResource::canAccess())->toBeFalse();
});
