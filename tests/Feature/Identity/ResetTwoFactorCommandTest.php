<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Shared\Audit\AuditLog;

it('turns off two-step verification from the console with an audited reason', function (): void {
    $admin = User::factory()->withTwoFactor()->create(['username' => 'only_admin']);

    $this->artisan('users:reset-two-factor', ['username' => 'only_admin'])->assertFailed();
    expect($admin->refresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->artisan('users:reset-two-factor', ['username' => 'only_admin', '--reason' => 'Lost phone, identity checked'])->assertSuccessful();

    expect($admin->refresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($admin->two_factor_recovery_codes)->toBeNull()
        ->and(AuditLog::query()->where('action', 'user.two_factor_reset')->latest('id')->value('after'))->toMatchArray(['reason' => 'Lost phone, identity checked', 'via' => 'console']);

    $this->artisan('users:reset-two-factor', ['username' => 'nobody', '--reason' => 'x'])->assertFailed();
});
