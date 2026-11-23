<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Actions\ApplyForService;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Enums\ServiceApplicationStatus;
use App\Domains\Sellers\Models\SellerLevel;
use App\Domains\Sellers\Models\SellerProfile;
use App\Filament\Resources\Sellers\SellerProfiles\Pages\ListSellerProfiles;
use App\Filament\Resources\Sellers\ServiceApplications\Pages\ListServiceApplications;
use App\Shared\Audit\AuditLog;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\CatalogFixture;

it('lets merchant managers manage sellers; support, moderators and buyers cannot', function (): void {
    $profile = SellerProfile::factory()->create();
    $manager = User::factory()->staff(StaffRole::MerchantManager)->create();

    expect(Gate::forUser($manager)->allows('update', $profile))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('moderate', $profile))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('update', SellerLevel::entry()))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('viewAny', SellerLevel::class))->toBeTrue();

    foreach ([StaffRole::Support, StaffRole::Moderator] as $role) {
        $staff = User::factory()->staff($role)->create();
        expect(Gate::forUser($staff)->allows('viewAny', SellerProfile::class))->toBeFalse();
    }

    expect(Gate::forUser(User::factory()->create())->allows('viewAny', SellerProfile::class))->toBeFalse()
        ->and(Gate::forUser(User::factory()->staff(StaffRole::Admin)->create())->allows('update', SellerLevel::entry()))->toBeTrue();
});

it('opens every seller admin screen for a merchant manager with 2FA', function (string $path): void {
    $profile = SellerProfile::factory()->create();
    $this->actingAs(User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create())
        ->get(str_replace('{id}', (string) $profile->id, $path))
        ->assertOk();
})->with([
    '/admin/sellers/stores',
    '/admin/sellers/stores/{id}/edit',
    '/admin/sellers/service-applications',
    '/admin/sellers/levels',
]);

it('suspends and reinstates a store from the admin with an audited history', function (): void {
    $profile = SellerProfile::factory()->create();
    $manager = User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create();
    $this->actingAs($manager);

    Livewire::test(ListSellerProfiles::class)
        ->callAction(TestAction::make('suspend')->table($profile), ['reason' => 'Chargebacks'])
        ->assertHasNoActionErrors();

    expect($profile->refresh()->status)->toBe(SellerStatus::Suspended)
        ->and($profile->status_reason)->toBe('Chargebacks')
        ->and($profile->statusChanges()->sole()->actor_id)->toBe($manager->id);

    Livewire::test(ListSellerProfiles::class)->callAction(TestAction::make('reinstate')->table($profile));
    expect($profile->refresh()->status)->toBe(SellerStatus::Active)
        ->and(AuditLog::query()->where('action', 'seller_profiles.updated')->count())->toBe(2);
});

it('passes and refuses service applications from the queue, never your own', function (): void {
    $gameCategory = CatalogFixture::build()['gameCategory'];
    $manager = User::factory()->staff(StaffRole::MerchantManager)->withTwoFactor()->create();
    $a = app(ApplyForService::class)->handle(SellerProfile::factory()->create(), $gameCategory->id);
    $b = app(ApplyForService::class)->handle(SellerProfile::factory()->create(), $gameCategory->id);
    $own = app(ApplyForService::class)->handle(SellerProfile::factory()->create(['user_id' => $manager->id]), $gameCategory->id);

    $this->actingAs($manager);

    Livewire::test(ListServiceApplications::class)
        ->assertCanSeeTableRecords([$a, $b, $own])
        ->callAction(TestAction::make('pass')->table($a))
        ->callAction(TestAction::make('refuse')->table($b), ['reason' => 'Need stock proof'])
        ->assertActionHidden(TestAction::make('pass')->table($own));

    expect($a->refresh()->status)->toBe(ServiceApplicationStatus::Passed)
        ->and($a->audited_by)->toBe($manager->id)
        ->and($b->refresh()->status)->toBe(ServiceApplicationStatus::Refused)
        ->and($b->reject_reason)->toBe('Need stock proof')
        ->and($own->refresh()->status)->toBe(ServiceApplicationStatus::Pending);
});

it('records staff level overrides in the audit log', function (): void {
    $profile = SellerProfile::factory()->create();
    $gold = SellerLevel::query()->where('code', 'gold')->sole();

    $profile->update(['seller_level_id' => $gold->id, 'level_locked' => true]);

    $audit = AuditLog::query()->where('action', 'seller_profiles.updated')->sole();
    expect($audit->after)->toMatchArray(['seller_level_id' => $gold->id, 'level_locked' => true]);
});
