<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\Staff;
use App\Shared\Ops\Backup;
use App\Shared\Ops\HealthCheck;
use App\Shared\Ops\ScheduleRuns;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Operations (docs/ADMIN_PLAN.md item 13): health checks (same as /health), the last run of every scheduled job,
 * backups on the backup disk and the display exchange rates. Read-only; admins only.
 */
final class Operations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Operations';

    protected static ?string $slug = 'operations';

    protected string $view = 'filament.pages.operations';

    public static function canAccess(): bool
    {
        return Staff::has();
    }

    /**
     * @return array<string, array{ok: bool, detail: string}>
     */
    public function checks(): array
    {
        return app(HealthCheck::class)->run();
    }

    /**
     * @return list<array{command: string, cron: string, lastRun: string, result: string, seconds: float|null, late: bool}>
     */
    public function schedule(): array
    {
        return ScheduleRuns::overview();
    }

    /**
     * @return array{last: string|null, required: bool, sets: list<array{stamp: string, files: int, size: string}>}
     */
    public function backups(): array
    {
        return [
            'last' => Backup::last(),
            'required' => config()->boolean('x2key.ops.backups_required'),
            'sets' => array_map(static fn (array $s): array => ['stamp' => $s['stamp'], 'files' => $s['files'], 'size' => number_format($s['bytes'] / 1048576, 1).' MB'], Backup::list()),
        ];
    }

    /**
     * @return list<array{currency: string, rate: string, source: string, fetched: string}>
     */
    public function rates(): array
    {
        return array_values(DB::table('fx_rates')->orderBy('currency')->get()->map(static fn (object $r): array => [
            'currency' => is_string($r->currency ?? null) ? $r->currency : '',
            'rate' => is_scalar($r->per_usd ?? null) ? rtrim(rtrim((string) $r->per_usd, '0'), '.') : '',
            'source' => is_string($r->source ?? null) ? $r->source : '',
            'fetched' => is_string($r->fetched_at ?? null) ? substr($r->fetched_at, 0, 16) : '',
        ])->all());
    }
}
