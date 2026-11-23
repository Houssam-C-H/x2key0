<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

it('sends guests to the normal login (no separate admin login)', function (): void {
    $this->get('/admin', ['Accept-Language' => 'en'])->assertRedirect('/en/login');
});

it('forbids non-staff users', function (): void {
    $this->actingAs(User::factory()->withTwoFactor()->create())->get('/admin')->assertForbidden();
});

it('sends staff without 2FA to set it up', function (StaffRole $role): void {
    $this->actingAs(User::factory()->staff($role)->create())
        ->get('/admin')
        ->assertRedirect('/settings/security');
})->with(StaffRole::cases());

it('opens the panel for staff with 2FA', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create())
        ->get('/admin')
        ->assertOk();
});

it('lets support list users and the certification queue', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create());

    $this->get('/admin/users')->assertOk();
    $this->get('/admin/certifications')->assertOk();
});

it('lets only admins edit users', function (): void {
    $target = User::factory()->create();

    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create())
        ->get("/admin/users/{$target->id}/edit")
        ->assertForbidden();

    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create())
        ->get("/admin/users/{$target->id}/edit")
        ->assertOk();
});

it('applies the user policy per role', function (): void {
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $support = User::factory()->staff(StaffRole::Support)->create();
    $admin = User::factory()->staff(StaffRole::Admin)->create();

    expect(Gate::forUser($buyer)->allows('viewAny', User::class))->toBeFalse()
        ->and(Gate::forUser($buyer)->allows('view', $buyer))->toBeTrue()
        ->and(Gate::forUser($buyer)->allows('view', $other))->toBeFalse()
        ->and(Gate::forUser($support)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($support)->allows('update', $other))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $other))->toBeTrue()
        ->and(Gate::forUser($support)->allows('create', User::class))->toBeFalse();
});

it('shows staff the admin link and keeps it from buyers', function (): void {
    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.isStaff', false));

    $this->actingAs(User::factory()->staff(StaffRole::Finance)->create())->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('auth.user.isStaff', true));
});
