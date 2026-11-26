<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * Production queue worker (Horizon): it must work the `payments` queue, or provider webhooks are never applied;
 * its dashboard is for admins with two-step verification only.
 */

it('makes Horizon work the payments queue before the default one', function (): void {
    expect(config('horizon.defaults.supervisor-1.queue'))->toBe(['payments', 'default']);
});

it('opens the Horizon dashboard to admins with 2FA only', function (): void {
    expect(Gate::forUser(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create())->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->staff(StaffRole::Admin)->create())->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create())->allows('viewHorizon'))->toBeFalse();
});
