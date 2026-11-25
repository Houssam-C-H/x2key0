<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domains\Reporting\DailySeries;
use App\Filament\Widgets\Concerns\StaffAccess;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Today at a glance (admins, finance): sales, orders, revenue, new users vs yesterday, 14-day sparklines. */
final class TodayStats extends StatsOverviewWidget
{
    use StaffAccess;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Today';

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return self::seesMoney();
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $s = DailySeries::last(14);

        return [
            self::stat('Sales (GMV)', $s['gmv'], true),
            self::stat('Paid orders', $s['orders'], false),
            self::stat('Revenue', $s['revenue'], true),
            self::stat('New users', $s['users'], false),
        ];
    }

    /**
     * @param  list<int>  $series  oldest day first, today last
     */
    private static function stat(string $label, array $series, bool $money): Stat
    {
        $today = $series[count($series) - 1] ?? 0;
        $yesterday = $series[count($series) - 2] ?? 0;
        $diff = $today - $yesterday;
        $format = static fn (int $v): string => $money ? self::usd($v) : number_format($v);

        return Stat::make($label, $format($today))
            ->description(($diff >= 0 ? '+' : '−').$format(abs($diff)).' vs yesterday')
            ->descriptionIcon($diff >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($diff >= 0 ? 'success' : 'danger')
            ->chart(array_map(static fn (int $v): float => (float) $v, $series));
    }
}
