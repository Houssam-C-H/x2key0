<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Exceptions\SellerException;
use App\Domains\Sellers\Models\SellerProfile;
use App\Domains\Sellers\Models\SellerStatusChange;
use App\Domains\Sellers\StateMachines\SellerProfileStateMachine;
use Illuminate\Database\Eloquent\MassAssignmentException;

beforeEach(function (): void {
    $this->machine = app(SellerProfileStateMachine::class);
    $this->staff = User::factory()->staff(StaffRole::MerchantManager)->create();
});

it('allows exactly the spec 6.3 transitions', function (): void {
    $allowed = [
        'inactive' => ['active', 'banned'],
        'active' => ['vacation', 'suspended', 'banned'],
        'vacation' => ['active', 'suspended', 'banned'],
        'suspended' => ['active', 'banned'],
        'banned' => [],
    ];

    foreach (SellerStatus::cases() as $from) {
        expect(array_map(fn (SellerStatus $s): string => $s->value, $from->allowedNext()))->toBe($allowed[$from->value]);
    }
});

it('suspends with a reason, reinstates, and writes a history row each time', function (): void {
    $profile = SellerProfile::factory()->create();

    $profile = $this->machine->suspend($profile, $this->staff, 'Too many disputes');
    expect($profile->status)->toBe(SellerStatus::Suspended)
        ->and($profile->status_reason)->toBe('Too many disputes')
        ->and($profile->user->isSeller())->toBeTrue(); // keeps read access

    $profile = $this->machine->reinstate($profile, $this->staff);
    expect($profile->status)->toBe(SellerStatus::Active)
        ->and($profile->status_reason)->toBeNull();

    $rows = SellerStatusChange::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->reason)->toBe('Too many disputes')
        ->and($rows[0]->actor_id)->toBe($this->staff->id)
        ->and($rows[1]->to_status)->toBe(SellerStatus::Active);
});

it('refuses illegal transitions and leaves no history', function (): void {
    $inactive = SellerProfile::factory()->status(SellerStatus::Inactive)->create();
    $banned = SellerProfile::factory()->status(SellerStatus::Banned)->create();
    $active = SellerProfile::factory()->create();

    expect(fn () => $this->machine->suspend($inactive, $this->staff, 'x'))->toThrow(SellerException::class)
        ->and(fn () => $this->machine->reinstate($active, $this->staff))->toThrow(SellerException::class)
        ->and(fn () => $this->machine->endVacation($active))->toThrow(SellerException::class)
        ->and(fn () => $this->machine->ban($banned, $this->staff, 'again'))->toThrow(SellerException::class)
        ->and(fn () => $this->machine->startVacation($active, now()->subDay()))->toThrow(SellerException::class);

    expect(SellerStatusChange::query()->count())->toBe(0);
});

it('bans from any non-banned status and hides the store', function (): void {
    $profile = SellerProfile::factory()->create(['slug' => 'gone-store']);

    $this->machine->ban($profile, $this->staff, 'Fraud');

    expect($profile->refresh()->status)->toBe(SellerStatus::Banned)
        ->and($profile->user->refresh()->isSeller())->toBeFalse();
    $this->get('/en/store/gone-store')->assertNotFound();
    $this->actingAs($profile->user)->get('/seller/store')->assertRedirect('/seller/onboarding');
});

it('keeps the status history append-only', function (): void {
    $profile = $this->machine->suspend(SellerProfile::factory()->create(), $this->staff, 'x');
    $row = SellerStatusChange::query()->sole();

    expect(fn () => $row->update(['reason' => 'edited']))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class)
        ->and($profile->status)->toBe(SellerStatus::Suspended);
});

it('does not let status be mass assigned', function (): void {
    $profile = SellerProfile::factory()->create();
    expect(fn () => $profile->fill(['status' => 'banned']))->toThrow(MassAssignmentException::class)
        ->and($profile->refresh()->status)->toBe(SellerStatus::Active);
});
