<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Identity\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff roles (spec 3.2). Idempotent; safe to run on every deploy.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (StaffRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
    }
}
