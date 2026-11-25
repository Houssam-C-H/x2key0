<?php

declare(strict_types=1);

namespace App\Domains\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-day series for the admin dashboard: paid orders, sales (GMV), platform revenue (commission + fees) and new
 * users, oldest day first, with zero-filled gaps. Works on PostgreSQL and SQLite (DATE()).
 */
final class DailySeries
{
    /**
     * @return array{days: list<string>, orders: list<int>, gmv: list<int>, revenue: list<int>, users: list<int>}
     */
    public static function last(int $days): array
    {
        $from = Carbon::today()->subDays($days - 1);
        $keys = [];
        for ($d = $from->copy(); $d->lte(Carbon::today()); $d->addDay()) {
            $keys[] = $d->toDateString();
        }

        $orders = self::byDay(DB::table('orders')->whereNotNull('paid_at')->where('paid_at', '>=', $from)
            ->selectRaw('DATE(paid_at) as d, COUNT(*) as n, COALESCE(SUM(total_amount), 0) as gmv, '
                .'COALESCE(SUM(commission_amount + buyer_fee_amount + addon_fee_amount + method_fee_amount), 0) as revenue')
            ->groupByRaw('DATE(paid_at)')->get(), ['n', 'gmv', 'revenue']);
        $users = self::byDay(DB::table('users')->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as n')->groupByRaw('DATE(created_at)')->get(), ['n']);

        return [
            'days' => $keys,
            'orders' => self::column($orders, $keys, 'n'),
            'gmv' => self::column($orders, $keys, 'gmv'),
            'revenue' => self::column($orders, $keys, 'revenue'),
            'users' => self::column($users, $keys, 'n'),
        ];
    }

    /**
     * @param  array<string, array<string, int>>  $rows
     * @param  list<string>  $days
     * @return list<int>
     */
    private static function column(array $rows, array $days, string $field): array
    {
        return array_map(static fn (string $d): int => $rows[$d][$field] ?? 0, $days);
    }

    /**
     * Rows keyed by day ("YYYY-MM-DD"), numeric columns as ints.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @param  list<string>  $fields
     * @return array<string, array<string, int>>
     */
    private static function byDay(Collection $rows, array $fields): array
    {
        $out = [];
        foreach ($rows as $row) {
            $day = is_scalar($row->d ?? null) ? substr((string) $row->d, 0, 10) : '';
            $values = [];
            foreach ($fields as $field) {
                $value = $row->{$field} ?? 0;
                $values[$field] = is_numeric($value) ? (int) $value : 0;
            }
            $out[$day] = $values;
        }

        return $out;
    }
}
