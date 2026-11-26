<?php

declare(strict_types=1);

use App\Domains\Admin\Actions\ChangePolicySetting;
use App\Domains\Admin\Support\MyAssignments;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\SellerProfile;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\StaffRoles;
use App\Filament\Support\StaffAccessMatrix;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/*
 * Staff roles matrix and "My work" (docs/ADMIN_PLAN.md item 15).
 */

it('computes screen access per role from the real rules and restores the signed-in user', function (): void {
    $admin = User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create();
    $this->actingAs($admin);

    $matrix = StaffAccessMatrix::build();
    $row = fn (string $screen): array => collect($matrix['rows'])->firstWhere('screen', $screen)['access'] ?? [];

    expect($matrix['roles'])->toBe(StaffRole::values())
        ->and($row('Platform settings'))->toMatchArray(['admin' => true, 'finance' => false, 'support' => false])
        ->and($row('Payments'))->toMatchArray(['finance' => true, 'support' => false])
        ->and($row('Chat moderation'))->toMatchArray(['support' => true, 'moderator' => true, 'finance' => false])
        ->and($row('My work'))->toMatchArray(['support' => true, 'finance' => true])
        ->and(Auth::id())->toBe($admin->id);

    Livewire::test(StaffRoles::class)->assertOk()->assertSee('Screens per role')->assertSee($admin->username);
});

it('lists my tickets, managed stores and approvals I can give, never my own', function (): void {
    $alice = User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create();
    $bob = User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create();
    $store = SellerProfile::factory()->create(['merchant_manager_id' => $alice->id, 'store_name' => 'Managed Store']);
    app(ChangePolicySetting::class)->request($bob, 'commission_bps', 900, 'Pricing review for Q1');

    $mine = MyAssignments::for($alice);
    expect($mine['approvals'])->toHaveCount(1)->and($mine['approvals'][0]['kind'])->toBe('Platform setting')
        ->and($mine['stores'][0]['store'])->toBe('Managed Store')
        ->and(MyAssignments::for($bob)['approvals'])->toBe([]);

    $this->actingAs($alice);
    Livewire::test(MyWork::class)->assertOk()->assertSee('Managed Store')->assertSee('Seller commission');
    expect(MyWork::getNavigationBadge())->toBe('1')->and($store->id)->toBeInt();
});
