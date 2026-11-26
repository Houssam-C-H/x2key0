<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A search that found nothing, counted per normalised query and language (docs/ADMIN_PLAN.md item 14). Anonymous.
 *
 * @property int $id
 * @property string $query
 * @property string $locale
 * @property int $hits
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $resolved_at
 */
final class SearchMiss extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['hits' => 'integer', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** Counts one empty search. Queries shorter than 2 characters are ignored; a failure never breaks the search page. */
    public static function record(string $text, string $locale): void
    {
        $query = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        if (mb_strlen($query) < 2) {
            return;
        }
        $query = mb_substr($query, 0, 100);

        try {
            $now = now();
            $updated = DB::table('search_misses')->where('query', $query)->where('locale', $locale)
                ->update(['hits' => DB::raw('hits + 1'), 'last_seen_at' => $now, 'resolved_at' => null]);
            if ($updated === 0) {
                DB::table('search_misses')->insertOrIgnore(['query' => $query, 'locale' => $locale, 'hits' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now]);
            }
        } catch (Throwable $e) {
            Log::warning('Search miss not recorded', ['error' => $e->getMessage()]);
        }
    }
}
