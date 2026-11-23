<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Versioned cache for catalog navigation data (mega menu, Game Index). Flushing bumps the version,
 * so every locale's entries go stale at once without needing cache tags.
 */
final class CatalogCache
{
    private const string VERSION_KEY = 'catalog.cache.version';

    private const int TTL_SECONDS = 3600;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(string $key, Closure $callback): mixed
    {
        $version = Cache::get(self::VERSION_KEY, 1);
        $version = is_int($version) ? $version : 1;

        return Cache::remember("catalog.{$version}.{$key}", self::TTL_SECONDS, $callback);
    }

    public static function flush(): void
    {
        $version = Cache::get(self::VERSION_KEY, 1);
        Cache::forever(self::VERSION_KEY, (is_int($version) ? $version : 1) + 1);
    }
}
