<?php

declare(strict_types=1);

namespace App\Domains\Reporting\Actions;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Reporting\Mail\WeeklySummaryMail;
use App\Domains\Reporting\ReportCsv;
use App\Domains\Reporting\Reports;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Weekly report e-mail (docs/ADMIN_PLAN.md item 18): the previous ISO week (Monday–Sunday, UTC) to every active
 * admin and finance user with a verified e-mail. Idempotent: the week is claimed in `report_digests` before sending,
 * so a second run for the same week sends nothing.
 */
final class SendWeeklySummary
{
    public function __construct(private readonly Reports $reports) {}

    /** @return int recipients (0 when this week was already sent or nobody qualifies) */
    public function handle(?Carbon $now = null): int
    {
        $now ??= now();
        $from = $now->copy()->utc()->subWeek()->startOfWeek(Carbon::MONDAY);
        $to = $from->copy()->endOfWeek(Carbon::SUNDAY);
        $period = $from->format('o-\WW');

        $recipients = User::query()
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('name', [StaffRole::Admin->value, StaffRole::Finance->value]))
            ->whereNotNull('email_verified_at')
            ->where('status', 'active')
            ->get();
        if ($recipients->isEmpty()) {
            return 0;
        }

        // Claim the week first; insertOrIgnore keeps a surrounding PostgreSQL transaction usable on a repeat.
        if (DB::table('report_digests')->insertOrIgnore(['period' => $period, 'recipients' => $recipients->count(), 'sent_at' => now()]) === 0) {
            return 0;
        }

        $r = $this->reports->summary($from, $to);
        $money = static fn (mixed $v): string => 'USD '.ReportCsv::decimal(is_numeric($v) ? (int) $v : 0);
        $pct = static fn (mixed $v): string => is_numeric($v) ? number_format(((int) $v) / 100, 2).' %' : '—';
        $figures = [
            'GMV' => $money($r['gmv']),
            'Orders paid / completed' => self::num($r['orders']).' / '.self::num($r['completed']),
            'Platform revenue' => $money($r['revenue']),
            'Take rate' => $pct($r['takeRateBps']),
            'Refunds' => $money($r['refunds']).' ('.$pct($r['refundRateBps']).')',
            'Chargebacks' => self::num($r['chargebacks']).' · '.$money($r['chargebackAmount']),
            'Dispute rate' => $pct($r['disputeRateBps']),
            'Coupon / affiliate cost' => $money($r['couponCost']).' / '.$money($r['affiliateCost']),
        ];
        $csv = ReportCsv::toString(ReportCsv::rows($r, $from, $to));
        $range = $from->toDateString().' to '.$to->toDateString().' (UTC)';
        $queues = [];
        foreach (is_array($r['queues']) ? $r['queues'] : [] as $queue => $count) {
            $queues[(string) $queue] = is_numeric($count) ? (int) $count : 0;
        }

        foreach ($recipients as $user) {
            Mail::to($user)->queue(new WeeklySummaryMail($period, $range, $figures, $queues, $csv));
        }

        return $recipients->count();
    }

    private static function num(mixed $v): string
    {
        return is_numeric($v) ? (string) (int) $v : '0';
    }
}
