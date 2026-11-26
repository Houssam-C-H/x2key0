<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\User;
use App\Domains\Reporting\ReportCsv;
use App\Domains\Reporting\Reports as ReportQueries;
use App\Shared\Audit\AuditLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Reports (spec Part 18, 17.1): GMV, take rate, refunds, chargebacks, disputes, coupon and affiliate cost, margin per
 * category type, top games and sellers for a date range, and the work queues. Finance and admins.
 */
final class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.pages.reports';

    public string $from = '';

    public string $to = '';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->hasStaffRole(StaffRole::Finance) || $user->hasStaffRole(StaffRole::Admin));
    }

    public function mount(): void
    {
        $this->from = now()->subDays(29)->toDateString();
        $this->to = now()->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        [$from, $to] = $this->range();

        return app(ReportQueries::class)->summary($from, $to);
    }

    /** CSV of the report for the chosen dates (docs/ADMIN_PLAN.md item 18); audited like every admin export. */
    public function exportCsv(): StreamedResponse
    {
        [$from, $to] = $this->range();
        $csv = ReportCsv::toString(ReportCsv::rows(app(ReportQueries::class)->summary($from, $to), $from, $to));
        app(AuditLogger::class)->log('admin.exported', null, null, ['list' => 'report', 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'report-'.$from->toDateString().'-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [Action::make('export')->label('Export CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')->action(fn (): StreamedResponse => $this->exportCsv())];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(): array
    {
        return [
            strtotime($this->from) !== false ? Carbon::parse($this->from)->startOfDay() : now()->subDays(29)->startOfDay(),
            strtotime($this->to) !== false ? Carbon::parse($this->to)->endOfDay() : now()->endOfDay(),
        ];
    }

    public static function money(mixed $cents): string
    {
        return 'USD '.number_format((is_numeric($cents) ? (int) $cents : 0) / 100, 2);
    }

    public static function pct(mixed $bps): string
    {
        return is_numeric($bps) ? number_format(((int) $bps) / 100, 2).' %' : '—';
    }
}
