<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Shared\Ops\HealthCheck;
use Filament\Widgets\Widget;

/** The /health checks (database, cache, scheduler, queue, ledger, payments) on the dashboard. */
final class SystemHealth extends Widget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.system-health';

    protected ?string $pollingInterval = '120s';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $checks = app(HealthCheck::class)->run();

        return ['checks' => $checks, 'healthy' => HealthCheck::healthy($checks)];
    }
}
