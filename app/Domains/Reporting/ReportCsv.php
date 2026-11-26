<?php

declare(strict_types=1);

namespace App\Domains\Reporting;

use Illuminate\Support\Carbon;

/**
 * The Reports summary as one CSV (docs/ADMIN_PLAN.md item 18): headline figures, margin per category type, top games,
 * top sellers and work queues, each block under its own heading row. Money in USD with two decimals, rates in %.
 */
final class ReportCsv
{
    /**
     * @param  array<string, mixed>  $r  Reports::summary()
     * @return list<list<string>>
     */
    public static function rows(array $r, Carbon $from, Carbon $to): array
    {
        $money = static fn (mixed $v): string => self::decimal(is_numeric($v) ? (int) $v : 0);
        $pct = static fn (mixed $v): string => is_numeric($v) ? number_format(((int) $v) / 100, 2) : '';

        $rows = [
            ['X2KEY report', $from->toDateString(), $to->toDateString()],
            [],
            ['Figure', 'Value'],
            ['GMV (USD)', $money($r['gmv'] ?? 0)],
            ['Orders paid', (string) self::int($r['orders'] ?? 0)],
            ['Orders completed', (string) self::int($r['completed'] ?? 0)],
            ['Platform revenue (USD)', $money($r['revenue'] ?? 0)],
            ['Take rate (%)', $pct($r['takeRateBps'] ?? null)],
            ['Refunds (USD)', $money($r['refunds'] ?? 0)],
            ['Refund rate (%)', $pct($r['refundRateBps'] ?? null)],
            ['Chargebacks', (string) self::int($r['chargebacks'] ?? 0)],
            ['Chargeback amount (USD)', $money($r['chargebackAmount'] ?? 0)],
            ['Chargeback ratio (%)', $pct($r['chargebackRatioBps'] ?? null)],
            ['Dispute rate (%)', $pct($r['disputeRateBps'] ?? null)],
            ['Coupon cost (USD)', $money($r['couponCost'] ?? 0)],
            ['Affiliate cost (USD)', $money($r['affiliateCost'] ?? 0)],
            [],
            ['Category type', 'Orders', 'GMV (USD)', 'Revenue (USD)', 'Margin (%)'],
        ];
        foreach (self::list($r['byType'] ?? []) as $t) {
            $rows[] = [self::text($t['type'] ?? ''), (string) self::int($t['orders'] ?? 0), $money($t['gmv'] ?? 0), $money($t['revenue'] ?? 0), $pct($t['marginBps'] ?? null)];
        }
        $rows[] = [];
        $rows[] = ['Top game', 'Orders', 'GMV (USD)'];
        foreach (self::list($r['topGames'] ?? []) as $g) {
            $rows[] = [self::text($g['name'] ?? ''), (string) self::int($g['orders'] ?? 0), $money($g['gmv'] ?? 0)];
        }
        $rows[] = [];
        $rows[] = ['Top seller', 'Orders', 'GMV (USD)', 'Disputes'];
        foreach (self::list($r['topSellers'] ?? []) as $s) {
            $rows[] = [self::text($s['name'] ?? ''), (string) self::int($s['orders'] ?? 0), $money($s['gmv'] ?? 0), (string) self::int($s['disputes'] ?? 0)];
        }
        $rows[] = [];
        $rows[] = ['Work queue', 'Open now'];
        foreach (is_array($r['queues'] ?? null) ? $r['queues'] : [] as $queue => $count) {
            $rows[] = [str_replace('_', ' ', (string) $queue), (string) self::int($count)];
        }

        return array_map(static fn (array $row): array => array_map(static fn (string $cell): string => self::safe($cell), $row), $rows);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    public static function toString(array $rows): string
    {
        $out = fopen('php://temp', 'w+');
        if ($out === false) {
            return '';
        }
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, $row, escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = array_filter($row, is_string(...), ARRAY_FILTER_USE_KEY);
            }
        }

        return $out;
    }

    public static function decimal(int $cents): string
    {
        $digits = str_pad((string) abs($cents), 3, '0', STR_PAD_LEFT);

        return ($cents < 0 ? '-' : '').substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    /** Cells starting with = + - @ are prefixed so spreadsheet apps never run them as formulas. */
    private static function safe(string $text): string
    {
        return $text !== '' && in_array($text[0], ['=', '+', '-', '@'], true) && ! is_numeric($text) ? "'".$text : $text;
    }

    private static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function text(mixed $v): string
    {
        return is_scalar($v) ? str_replace('_', ' ', (string) $v) : '';
    }
}
