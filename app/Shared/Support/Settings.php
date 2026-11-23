<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Shared\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * Runtime policy values. Lookup order: `settings` table override → config('x2key.policy').
 * Unknown keys are rejected so typos fail loudly instead of silently using a default.
 * Dot notation reaches into nested defaults, e.g. `settlement_hold_days.accounts`.
 */
final class Settings
{
    private const string CACHE_KEY = 'x2key.settings.overrides';

    /** @var array<string, mixed>|null */
    private ?array $overrides = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly Config $config,
        private readonly AuditLogger $audit,
    ) {}

    public function get(string $key): mixed
    {
        $root = explode('.', $key, 2)[0];
        $this->assertKnown($root);

        $overrides = $this->overrides();

        if (array_key_exists($root, $overrides)) {
            return data_get([$root => $overrides[$root]], $key);
        }

        return $this->config->get("x2key.policy.{$key}");
    }

    public function int(string $key): int
    {
        $value = $this->get($key);

        if (! is_int($value)) {
            throw new InvalidArgumentException("Setting [{$key}] is not an integer.");
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * Overrides a top-level policy key. Audited.
     */
    public function set(string $key, mixed $value, ?Authenticatable $by = null): void
    {
        $this->assertKnown($key);

        $before = $this->get($key);

        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $by?->getAuthIdentifier()],
        );

        $this->flush();

        $this->audit->log('settings.updated', null, ['key' => $key, 'value' => $before], ['key' => $key, 'value' => $value], $by);
    }

    /**
     * Removes an override so the config default applies again. Audited.
     */
    public function reset(string $key, ?Authenticatable $by = null): void
    {
        $this->assertKnown($key);

        $before = $this->get($key);
        Setting::query()->whereKey($key)->delete();
        $this->flush();

        $this->audit->log('settings.reset', null, ['key' => $key, 'value' => $before], null, $by);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = $this->config->get('x2key.policy', []);

        return array_replace($defaults, $this->overrides());
    }

    public function flush(): void
    {
        $this->overrides = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        /** @var array<string, mixed> $overrides */
        $overrides = $this->cache->rememberForever(
            self::CACHE_KEY,
            fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );

        return $this->overrides = $overrides;
    }

    private function assertKnown(string $key): void
    {
        /** @var array<string, mixed> $defaults */
        $defaults = $this->config->get('x2key.policy', []);

        if (! array_key_exists($key, $defaults)) {
            throw new InvalidArgumentException("Unknown setting [{$key}].");
        }
    }
}
