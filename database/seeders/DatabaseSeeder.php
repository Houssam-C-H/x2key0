<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Identity\Actions\RegisterUser;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([UserLevelSeeder::class, RoleSeeder::class, CatalogSeeder::class]);

        if (! app()->environment('local', 'testing')) {
            return;
        }

        // Local demo accounts (password: "password"). The admin must enable 2FA before /admin opens.
        $register = app(RegisterUser::class);

        foreach ([['admin', StaffRole::Admin], ['buyer', null]] as [$username, $role]) {
            if (User::query()->where('username', $username.'_demo')->exists()) {
                continue;
            }

            $user = $register->handle([
                'username' => $username.'_demo',
                'name' => ucfirst($username),
                'email' => $username.'@x2key.test',
                'password' => 'password',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            if ($role !== null) {
                $user->assignRole($role->value);
            }
        }
    }
}
