<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Content\Models\HomeModule;
use Illuminate\Database\Seeder;

/** The 12 home modules in the observed order (spec 1.3), all enabled. Existing rows keep staff changes. */
final class HomeModuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (HomeModule::CODES as $i => $code) {
            HomeModule::query()->firstOrCreate(['code' => $code], ['sort' => ($i + 1) * 10, 'is_enabled' => true]);
        }
    }
}
