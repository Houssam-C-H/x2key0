<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use Illuminate\Support\Facades\DB;

/**
 * `privacy:prune` (daily, spec Part 23 "retention per table"): deletes data kept only for convenience or short-term
 * security once it is older than `x2key.retention_days`. Orders, payments, journals, disputes, tickets, reviews and
 * audit logs are records and are never pruned here.
 */
final class PruneRetention
{
    /**
     * @return array<string, int> rows deleted per rule
     */
    public function handle(): array
    {
        $days = static fn (string $key): int => max(1, config()->integer("x2key.retention_days.{$key}", 365));

        return [
            'browsing_history' => DB::table('browsing_history')->where('viewed_at', '<', now()->subDays($days('browsing_history')))->delete(),
            'login_events' => DB::table('login_events')->where('created_at', '<', now()->subDays($days('login_events')))->delete(),
            'user_sessions_revoked' => DB::table('user_sessions')->whereNotNull('revoked_at')->where('revoked_at', '<', now()->subDays($days('user_sessions_revoked')))->delete(),
            'read_notifications' => DB::table('user_inbox')->whereNull('announcement_id')->whereNotNull('read_at')->whereNull('starred_at')
                ->where('created_at', '<', now()->subDays($days('read_notifications')))->delete(),
            'risk_flags_resolved' => DB::table('risk_flags')->where('status', 'resolved')->where('resolved_at', '<', now()->subDays($days('risk_flags_resolved')))->delete(),
            'jobs_failed' => DB::table('failed_jobs')->where('failed_at', '<', now()->subDays($days('jobs_failed')))->delete(),
        ];
    }
}
