<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Filament\Pages\Operations;
use App\Filament\Pages\QueueHealthPage;
use App\Shared\Audit\AuditLog;
use App\Shared\Ops\FailedJob;
use App\Shared\Ops\MailLog;
use App\Shared\Ops\ScheduleRuns;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Queues & e-mail (item 12) and Operations (item 13) pages.
 */

function failJob(string $error = 'RuntimeException: PSP timeout'): FailedJob
{
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'sync',
        'queue' => 'payments',
        'payload' => (string) json_encode(['uuid' => $uuid, 'displayName' => 'App\\Domains\\Payments\\Jobs\\ProcessPaymentEvent', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]),
        'exception' => $error."\n#0 stack",
        'failed_at' => now(),
    ]);

    return FailedJob::query()->where('uuid', $uuid)->sole();
}

it('logs sent e-mails without their body', function (): void {
    Mail::raw('Private body text', fn ($m) => $m->to('someone@example.com')->subject('Your order shipped'));

    $row = MailLog::query()->sole();
    expect($row->to)->toBe('someone@example.com')->and($row->subject)->toBe('Your order shipped')
        ->and(json_encode($row->toArray()))->not->toContain('Private body');
});

it('shows queue sizes, failed jobs and mails, and forgets a failed job (audited)', function (): void {
    $job = failJob();
    Mail::raw('x', fn ($m) => $m->to('a@example.com')->subject('Hello mail'));
    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create());

    Livewire::test(QueueHealthPage::class)
        ->assertOk()
        ->assertSee('Queue “payments” waiting')
        ->assertSee('ProcessPaymentEvent')
        ->assertSee('PSP timeout')
        ->assertSee('Hello mail')
        ->callAction(TestAction::make('forget')->table($job))
        ->assertCanNotSeeTableRecords([$job]);

    expect(AuditLog::query()->where('action', 'ops.failed_job_forgotten')->count())->toBe(1);
});

it('records scheduled runs and shows checks, schedule, backups and rates', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'ledger:verify'));
    expect($event)->not->toBeNull();
    $event->exitCode = 0;
    ScheduleRuns::finished(new ScheduledTaskFinished($event, 1.5));
    DB::table('fx_rates')->insert(['currency' => 'EUR', 'per_usd' => '0.92000000', 'source' => 'test', 'fetched_at' => now()]);

    $row = collect(ScheduleRuns::overview())->firstWhere('command', 'ledger:verify');
    expect($row['result'])->toBe('ok')->and($row['seconds'])->toBe(1.5);

    $this->actingAs(User::factory()->staff(StaffRole::Admin)->withTwoFactor()->create());
    Livewire::test(Operations::class)
        ->assertOk()
        ->assertSee('Health checks')
        ->assertSee('ledger:verify')
        ->assertSee('EUR')
        ->assertSee('0.92');
});

it('is admin only', function (): void {
    $this->actingAs(User::factory()->staff(StaffRole::Finance)->withTwoFactor()->create());
    expect(Operations::canAccess())->toBeFalse()->and(QueueHealthPage::canAccess())->toBeFalse();
});
