<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Filament\Resources\Audit\AuditLogResource;
use App\Filament\Resources\Audit\Pages\ListAuditLogs;
use App\Shared\Audit\AuditLog;
use App\Shared\Audit\AuditLogger;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * Audit log screen (docs/ADMIN_PLAN.md item 2).
 */

it('lists entries with who, what, record and the change, and filters them', function (): void {
    $admin = User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create(['username' => 'audit_admin']);
    $mod = User::factory()->staff(StaffRole::Moderator)->create(['username' => 'audit_mod']);
    $target = User::factory()->create();
    $audit = app(AuditLogger::class);
    $mine = $audit->log('users.status_changed', $target, ['status' => 'active'], ['status' => 'suspended', 'reason' => 'spam'], $mod);
    $other = $audit->log('settings.updated', null, ['key' => 'x', 'value' => 1], ['key' => 'x', 'value' => 2], $admin);
    $this->actingAs($admin);

    Livewire::test(ListAuditLogs::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine, $other])
        ->assertSee('status: active → suspended')
        ->filterTable('actor_id', $mod->id)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()
        ->filterTable('subject', ['type' => $target->getMorphClass(), 'id' => (string) $target->id])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()
        ->filterTable('action', 'settings.updated')
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$mine])
        ->resetTableFilters()
        ->mountAction(TestAction::make('details')->table($mine))
        ->assertMountedActionModalSee('spam');

    expect(AuditLogResource::urlFor($target))->toContain('subject');
});

it('never shows secrets and is read-only', function (): void {
    $log = app(AuditLogger::class)->log('test.secret', null, null, ['password' => 'hunter2', 'nested' => ['two_factor_secret' => 'abc']]);
    expect(AuditLogResource::summary($log->before, $log->after))->not->toContain('hunter2')->not->toContain('abc')
        ->and(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit($log))->toBeFalse()
        ->and(fn () => $log->update(['action' => 'changed']))->toThrow(LogicException::class);
});

it('is open to admins only', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Support)->withTwoFactor()->create());
    $this->get('/admin/audit-log')->assertForbidden();

    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create());
    $this->get('/admin/audit-log')->assertOk();
    expect(AuditLog::query()->count())->toBeGreaterThanOrEqual(0);
});
