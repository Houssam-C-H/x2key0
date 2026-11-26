<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domains\Reporting\Reports;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/** What staff must work on now (all staff), each linking to its queue; orange while something waits. */
final class WorkQueues extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Work queues';

    protected ?string $pollingInterval = '60s';

    /** @var array<string, array{string, string}> queue => label, admin path */
    private const array QUEUES = [
        'payment_review' => ['Payments to review', '/admin/orders'],
        'risk_flags' => ['Risk flags', '/admin/risk-flags'],
        'disputes_escalated' => ['Escalated disputes', '/admin/disputes'],
        'tickets_pending' => ['Tickets waiting', '/admin/tickets'],
        'offers_review' => ['Offers to review', '/admin/offers'],
        'withdrawals' => ['Withdrawals to approve', '/admin/withdrawals'],
        'certifications' => ['ID checks', '/admin/certifications'],
        'campaign_applications' => ['Campaign applications', '/admin/campaign-applications'],
        'affiliate_videos' => ['Affiliate videos', '/admin/affiliate-videos'],
        'ledger_adjustments' => ['Ledger adjustments', '/admin/ledger-adjustments'],
    ];

    /** Live refresh relayed from the private `staff` channel (docs/ADMIN_PLAN.md item 11); polling stays as fallback. */
    #[On('staff-queues-changed')]
    public function refreshQueues(): void {}

    protected function getColumns(): int
    {
        return 5;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $counts = app(Reports::class)->queues();
        $stats = [];
        foreach (self::QUEUES as $key => [$label, $url]) {
            $n = $counts[$key] ?? 0;
            $stats[] = Stat::make($label, number_format($n))
                ->description($n === 0 ? 'All clear' : 'Open the queue')
                ->color($n === 0 ? 'success' : 'warning')
                ->url($url);
        }

        return $stats;
    }
}
