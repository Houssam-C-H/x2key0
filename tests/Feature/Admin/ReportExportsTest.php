<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Reporting\Actions\SendWeeklySummary;
use App\Domains\Reporting\Mail\WeeklySummaryMail;
use App\Domains\Reporting\ReportCsv;
use App\Domains\Reporting\Reports;
use App\Filament\Pages\Reports as ReportsPage;
use App\Shared\Audit\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * Report CSV export and the weekly e-mail summary (docs/ADMIN_PLAN.md item 18).
 */

it('builds the report CSV with every block and exports it from the page (audited)', function (): void {
    $from = now()->subDays(6)->startOfDay();
    $rows = ReportCsv::rows(app(Reports::class)->summary($from, now()), $from, now());
    $firstCells = array_map(fn (array $r): string => $r[0] ?? '', $rows);

    expect($firstCells)->toContain('GMV (USD)', 'Category type', 'Top game', 'Top seller', 'Work queue')
        ->and(ReportCsv::decimal(12345))->toBe('123.45');

    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());
    Livewire::test(ReportsPage::class)->callAction('export')->assertFileDownloaded();
    expect(AuditLog::query()->where('action', 'admin.exported')->value('after'))->toMatchArray(['list' => 'report']);
});

it('mails last week to admins and finance once per week, with the CSV attached', function (): void {
    Mail::fake();
    $admin = User::factory()->staff(StaffRole::Admin)->create();
    $finance = User::factory()->staff(StaffRole::Finance)->create();
    User::factory()->staff(StaffRole::Support)->create();
    User::factory()->create();
    $monday = Carbon::parse('2026-11-23 07:00:00', 'UTC');

    expect(app(SendWeeklySummary::class)->handle($monday))->toBe(2)
        ->and(app(SendWeeklySummary::class)->handle($monday))->toBe(0); // same week: nothing again

    Mail::assertQueued(WeeklySummaryMail::class, 2);
    Mail::assertQueued(WeeklySummaryMail::class, fn (WeeklySummaryMail $m): bool => $m->hasTo($admin->email) && $m->period === '2026-W47' && count($m->attachments()) === 1);
    Mail::assertQueued(WeeklySummaryMail::class, fn (WeeklySummaryMail $m): bool => $m->hasTo($finance->email));

    $this->artisan('reports:weekly-summary')->assertSuccessful();
});
