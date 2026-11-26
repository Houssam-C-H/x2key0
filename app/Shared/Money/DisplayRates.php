<?php

declare(strict_types=1);

namespace App\Shared\Money;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Display exchange rates (ADR-036). `refresh()` loads units-per-USD for the display currencies from a free,
 * keyless source (open.er-api.com, USD base; attribution shown at checkout) and stores them; `for()` serves the
 * rate of one currency from a cache. Rates are only for showing approximate prices — X2KEY always charges USD.
 * A rate older than three days is ignored, so stale data never shows.
 */
final class DisplayRates
{
    public const string SOURCE = 'open.er-api.com';

    public const string URL = 'https://open.er-api.com/v6/latest/USD';

    private const int MAX_AGE_HOURS = 72;

    /** @return int currencies stored */
    public function refresh(): int
    {
        $response = Http::timeout(10)->acceptJson()->get(self::URL);
        $rates = $response->json('rates');
        if (! $response->successful() || $response->json('result') !== 'success' || ! is_array($rates)) {
            throw new \RuntimeException('exchange rates unavailable ('.$response->status().')');
        }

        $stored = 0;
        foreach (config()->array('x2key.display_currencies') as $currency) {
            if (! is_string($currency) || $currency === 'USD') {
                continue;
            }
            $rate = $rates[$currency] ?? null;
            if (! is_numeric($rate) || (float) $rate <= 0) {
                continue;
            }
            DB::table('fx_rates')->updateOrInsert(['currency' => $currency], [
                'per_usd' => number_format((float) $rate, 8, '.', ''),
                'source' => self::SOURCE,
                'fetched_at' => now(),
            ]);
            $stored++;
        }
        Cache::forget('fx:rates');

        return $stored;
    }

    /** Units of $currency per 1 USD, or null (USD, unknown, or too old). */
    public function for(string $currency): ?float
    {
        if ($currency === 'USD') {
            return null;
        }

        try {
            /** @var array<string, float> $all */
            $all = Cache::remember('fx:rates', 3600, static function (): array {
                $out = [];
                foreach (DB::table('fx_rates')->where('fetched_at', '>=', now()->subHours(self::MAX_AGE_HOURS))->get() as $row) {
                    if (is_string($row->currency ?? null) && is_numeric($row->per_usd ?? null)) {
                        $out[$row->currency] = (float) $row->per_usd;
                    }
                }

                return $out;
            });
        } catch (Throwable $e) {
            Log::warning('display rates unavailable', ['error' => $e->getMessage()]);

            return null;
        }

        return $all[$currency] ?? null;
    }
}
