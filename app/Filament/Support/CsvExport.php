<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Shared\Audit\AuditLogger;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Export selected" for admin lists (docs/ADMIN_PLAN.md item 17): a CSV of the chosen rows with fixed, non-secret
 * columns. Every export is audit-logged (what, how many rows, by whom). Cells starting with = + - @ are prefixed so
 * spreadsheet apps never run them as formulas.
 */
final class CsvExport
{
    /**
     * @param  array<string, Closure(Model): (string|int|float|bool|null)>  $columns  header => value
     */
    public static function bulkAction(string $name, array $columns): BulkAction
    {
        return BulkAction::make('export')->label('Export selected (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records): StreamedResponse => self::download($name, $columns, $records));
    }

    /**
     * @param  array<string, Closure(Model): (string|int|float|bool|null)>  $columns
     * @param  Collection<int, Model>  $records
     */
    public static function download(string $name, array $columns, Collection $records): StreamedResponse
    {
        app(AuditLogger::class)->log('admin.exported', null, null, ['list' => $name, 'rows' => $records->count(), 'columns' => array_keys($columns)], Staff::user());

        return response()->streamDownload(function () use ($columns, $records): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_keys($columns), escape: '');
            foreach ($records as $record) {
                fputcsv($out, array_map(static fn (Closure $value): string => self::cell($value($record)), array_values($columns)), escape: '');
            }
            fclose($out);
        }, $name.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function cell(string|int|float|bool|null $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
    }
}
