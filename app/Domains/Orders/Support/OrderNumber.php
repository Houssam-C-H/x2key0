<?php

declare(strict_types=1);

namespace App\Domains\Orders\Support;

use Illuminate\Support\Facades\DB;

/**
 * Order numbers `X2K{yyMMdd}{seq}` (spec Part 5, OPEN_QUESTIONS #3): a per-day counter, zero-padded to 4
 * digits (it simply grows past 9999). The counter row is locked, so concurrent checkouts never share a number.
 * Must run inside the order's transaction.
 */
final class OrderNumber
{
    public static function next(): string
    {
        $day = now()->utc()->format('ymd');

        DB::table('order_number_sequences')->insertOrIgnore(['day' => $day, 'last_value' => 0]);
        $current = DB::table('order_number_sequences')->where('day', $day)->lockForUpdate()->value('last_value');
        $next = (is_numeric($current) ? (int) $current : 0) + 1;
        DB::table('order_number_sequences')->where('day', $day)->update(['last_value' => $next]);

        return 'X2K'.$day.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
