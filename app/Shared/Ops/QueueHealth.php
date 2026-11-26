<?php

declare(strict_types=1);

namespace App\Shared\Ops;

use App\Domains\Identity\Models\User;
use App\Shared\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Queue and e-mail health for the admin (docs/ADMIN_PLAN.md item 12): queue sizes, failed jobs (retry / forget, both
 * audited), mails sent and push subscriptions. Admins only (the screen checks).
 */
final class QueueHealth
{
    /** Queues the app dispatches to (ProcessPaymentEvent uses `payments`). */
    public const array QUEUES = ['default', 'payments'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<string, int|null> queue => waiting jobs (null when the queue backend cannot be reached)
     */
    public function sizes(): array
    {
        $sizes = [];
        foreach (self::QUEUES as $queue) {
            try {
                $sizes[$queue] = Queue::size($queue);
            } catch (Throwable) {
                $sizes[$queue] = null;
            }
        }

        return $sizes;
    }

    /**
     * @return array{failed24h: int, failedTotal: int, mails24h: int, pushSubscriptions: int, pushUsers: int, pushNew7d: int}
     */
    public function counters(): array
    {
        return [
            'failed24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
            'failedTotal' => DB::table('failed_jobs')->count(),
            'mails24h' => DB::table('mail_log')->where('sent_at', '>=', now()->subDay())->count(),
            'pushSubscriptions' => DB::table('push_subscriptions')->count(),
            'pushUsers' => DB::table('push_subscriptions')->distinct()->count('user_id'),
            'pushNew7d' => DB::table('push_subscriptions')->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    public function retry(User $staff, string $uuid): void
    {
        $this->assertKnown($uuid);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit->log('ops.failed_job_retried', null, null, ['uuid' => $uuid], $staff);
    }

    public function forget(User $staff, string $uuid): void
    {
        $this->assertKnown($uuid);
        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->audit->log('ops.failed_job_forgotten', null, null, ['uuid' => $uuid], $staff);
    }

    private function assertKnown(string $uuid): void
    {
        if (! DB::table('failed_jobs')->where('uuid', $uuid)->exists()) {
            throw new DomainException('This failed job no longer exists.');
        }
    }
}
