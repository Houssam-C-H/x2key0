<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonException;

/**
 * `lang:missing {locale?} {--export=} {--import=}`: progress of each interface language, a CSV of the strings still
 * showing in English for a translator, and the import of the filled-in CSV (placeholders checked).
 */
final class LangMissing extends Command
{
    protected $signature = 'lang:missing {locale? : one language code; all when omitted} {--export= : write a CSV (key,english,translation) of the missing strings} {--import= : read a translated CSV back into lang/<locale>.json}';

    protected $description = 'Show untranslated strings per language, export them for a translator, import the result';

    public function handle(): int
    {
        $en = $this->load('en');
        $locale = $this->argument('locale');
        $codes = is_string($locale) ? [$locale] : array_values(array_filter(array_keys(config()->array('x2key.locales')), static fn (mixed $c): bool => is_string($c) && $c !== 'en'));

        if (is_string($locale) && ! array_key_exists($locale, config()->array('x2key.locales'))) {
            $this->error("Unknown locale {$locale}.");

            return self::FAILURE;
        }

        $import = $this->option('import');
        if (is_string($import)) {
            if (! is_string($locale)) {
                $this->error('Name the language to import into, e.g. lang:missing es --import=es.csv');

                return self::FAILURE;
            }

            return $this->import($locale, $import, $en);
        }

        $rows = [];
        foreach ($codes as $code) {
            $done = $this->load($code);
            $missing = array_diff_key($en, $done);
            $rows[] = [$code, count($en) - count($missing), count($en), count($missing)];

            $export = $this->option('export');
            if (is_string($export)) {
                $path = count($codes) > 1 ? preg_replace('/(\.csv)?$/', "-{$code}.csv", $export, 1) : $export;
                $handle = fopen((string) $path, 'w');
                if ($handle === false) {
                    $this->error("Cannot write {$path}.");

                    return self::FAILURE;
                }
                fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheet apps open accents and Arabic correctly
                fputcsv($handle, ['key', 'english', 'translation'], escape: '');
                foreach ($missing as $key => $text) {
                    fputcsv($handle, [$key, $text, ''], escape: '');
                }
                fclose($handle);
                $this->line("Wrote {$path}");
            }
        }

        $this->table(['Language', 'Translated', 'Total', 'Showing in English'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $en
     */
    private function import(string $locale, string $path, array $en): int
    {
        $handle = is_file($path) ? fopen($path, 'r') : false;
        if ($handle === false) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $current = $this->load($locale);
        $added = 0;
        $refused = [];
        $first = true;

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if ($first) {
                $first = false;

                continue; // header
            }

            $key = isset($row[0]) ? preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]) : '';
            $text = trim((string) ($row[2] ?? ''));
            if ($key === '' || $text === '' || ! isset($en[$key])) {
                continue;
            }

            preg_match_all('/:\w+/', $en[$key], $expected);
            preg_match_all('/:\w+/', $text, $actual);
            if ($expected[0] !== $actual[0]) {
                $refused[] = $key;

                continue;
            }

            $current[$key] = $text;
            $added++;
        }
        fclose($handle);

        // Keep English key order so diffs stay readable.
        $ordered = array_intersect_key(array_replace($en, $current), $current);
        file_put_contents(lang_path("{$locale}.json"), json_encode($ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->info("Imported {$added} strings into lang/{$locale}.json.");
        if ($refused !== []) {
            $this->warn('Skipped (placeholders such as :count must stay as in English): '.implode(', ', $refused));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function load(string $code): array
    {
        $path = lang_path("{$code}.json");
        if (! is_file($path)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $out = [];
        foreach (is_array($data) ? $data : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
