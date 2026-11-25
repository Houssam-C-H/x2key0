<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domains\Reporting\DailySeries;
use App\Filament\Widgets\Concerns\StaffAccess;
use Filament\Widgets\ChartWidget;

/** Last 30 days of sales and revenue in USD (admins, finance). */
final class SalesChart extends ChartWidget
{
    use StaffAccess;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Sales, last 30 days (USD)';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return self::seesMoney();
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $s = DailySeries::last(30);

        return [
            'labels' => array_map(static fn (string $d): string => substr($d, 5), $s['days']),
            'datasets' => [
                // Brand red / ink (docs/BRAND.md); Chart.js needs literal colours.
                ['label' => 'Sales', 'data' => array_map(static fn (int $c): float => $c / 100, $s['gmv']), 'borderColor' => '#d91a21', 'backgroundColor' => 'rgba(217,26,33,0.08)', 'fill' => true, 'tension' => 0.3],
                ['label' => 'Revenue', 'data' => array_map(static fn (int $c): float => $c / 100, $s['revenue']), 'borderColor' => '#111111', 'tension' => 0.3],
            ],
        ];
    }
}
