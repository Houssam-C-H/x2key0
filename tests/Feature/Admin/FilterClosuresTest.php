<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use Livewire\Livewire;

/*
 * Filament injects filter-closure arguments by name: a closure written `fn (Builder $q)` gets a fresh query instead of
 * the table's, so the filter silently does nothing. Table filters must name it `$query`.
 */

it('names every Filament filter query argument $query', function (): void {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (preg_match_all('/(?:->query\(|->queries\(|\b(?:true|false|blank):)\s*(?:static\s+)?(?:fn|function)\s*\(\s*Builder\s+\$(?!query\b)\w+/', $code, $m) > 0) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

it('applies the "Assigned to me" ticket filter', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create());

    $sql = Livewire::test(ListTickets::class)->filterTable('mine')->instance()->getFilteredTableQuery()?->toRawSql();

    expect($sql)->toContain('assigned_to');
});
