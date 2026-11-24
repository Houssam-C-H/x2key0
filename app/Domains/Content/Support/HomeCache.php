<?php

declare(strict_types=1);

namespace App\Domains\Content\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache for the shared (not per-viewer) parts of the home page and the footer. A version number in the key lets
 * one call drop everything when staff edit content or the trending job runs.
 */
final class HomeCache
{
    private const string VERSION_KEY = 'home:version';

    private const int TTL_SECONDS = 600;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(string $key, Closure $callback): mixed
    {
        $version = Cache::get(self::VERSION_KEY, 1);

        return Cache::remember('home:'.(is_int($version) ? $version : 1).':'.$key, self::TTL_SECONDS, $callback);
    }

    public static function flush(): void
    {
        $version = Cache::get(self::VERSION_KEY, 1);
        Cache::forever(self::VERSION_KEY, (is_int($version) ? $version : 1) + 1);
    }
}
