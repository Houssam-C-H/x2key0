<?php

declare(strict_types=1);

use App\Shared\Ops\Backup;
use App\Shared\Ops\HealthCheck;
use App\Shared\Ops\OpsAlerts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * Nightly backups to a separate disk with retention and a freshness check; alerts once on failure, hourly while
 * still failing, once on recovery.
 */

beforeEach(function (): void {
    config(['x2key.ops.backup_disk' => 'backups']);
    Storage::fake('backups');
});

it('backs up the database and uploads with checksums, then prunes old sets', function (): void {
    Storage::disk('backups')->put('backups/20200101T000000Z/manifest.json', '{}');
    Storage::fake('public')->put('games/icons/a.png', 'png-bytes');
    File::ensureDirectoryExists(storage_path('app/public/games/icons'));
    file_put_contents(storage_path('app/public/zz-backup-test.txt'), 'hello');

    try {
        $this->artisan('ops:backup')->assertSuccessful();
    } finally {
        @unlink(storage_path('app/public/zz-backup-test.txt'));
    }

    $dirs = Storage::disk('backups')->directories('backups');
    expect($dirs)->toHaveCount(1);
    $files = Storage::disk('backups')->files($dirs[0]);
    $names = array_map('basename', $files);
    // Inside the test transaction SQLite cannot VACUUM INTO, so the dump is SQL (database.sqlite outside one).
    expect($names)->toContain('database.sql', 'uploads.tar.gz', 'manifest.json')
        ->and((string) Storage::disk('backups')->get($dirs[0].'/database.sql'))->toContain('CREATE TABLE "users"');

    $manifest = json_decode((string) Storage::disk('backups')->get($dirs[0].'/manifest.json'), true);
    expect($manifest['files']['database.sql']['sha256'])->toBe(hash('sha256', (string) Storage::disk('backups')->get($dirs[0].'/database.sql')))
        ->and(Backup::age())->toBeLessThan(60);
});

it('fails the health check when backups are required and stale', function (): void {
    config(['x2key.ops.backups_required' => true]);
    Cache::forget(Backup::LAST_KEY);
    expect(app(HealthCheck::class)->run()['backup']['ok'])->toBeFalse();

    Cache::forever(Backup::LAST_KEY, ['at' => now()->subHours(30)->getTimestamp(), 'stamp' => 'x']);
    expect(app(HealthCheck::class)->run()['backup']['ok'])->toBeFalse();

    Cache::forever(Backup::LAST_KEY, ['at' => now()->subHours(2)->getTimestamp(), 'stamp' => 'x']);
    expect(app(HealthCheck::class)->run()['backup'])->toBe(['ok' => true, 'detail' => 'last backup 2 h ago']);

    config(['x2key.ops.backups_required' => false]);
    expect(app(HealthCheck::class)->run())->not->toHaveKey('backup');
});

it('alerts once on failure, again after the repeat window, and once on recovery', function (): void {
    Mail::fake();
    Http::fake();
    config(['x2key.ops.alert_email' => 'ops@x2key.test', 'x2key.ops.alert_slack_webhook' => 'https://hooks.slack.test/abc', 'x2key.ops.alert_repeat_minutes' => 60]);
    $alerts = app(OpsAlerts::class);
    $down = ['queue' => ['ok' => false, 'detail' => '3 failed job(s)'], 'database' => ['ok' => true, 'detail' => 'reachable']];

    expect($alerts->notify($down))->toHaveCount(1)
        ->and($alerts->notify($down))->toHaveCount(0);

    $this->travel(61)->minutes();
    expect($alerts->notify($down))->toHaveCount(1);

    $up = ['queue' => ['ok' => true, 'detail' => '0 failed job(s)'], 'database' => ['ok' => true, 'detail' => 'reachable']];
    $sent = $alerts->notify($up);
    expect($sent)->toHaveCount(1)->and($sent[0])->toContain('RECOVERED queue')
        ->and($alerts->notify($up))->toHaveCount(0);

    Http::assertSentCount(3);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.slack.test/abc' && str_contains((string) $request['text'], 'queue'));
});
