<?php

declare(strict_types=1);

namespace App\Shared\Support;

use JsonException;

/**
 * How far each language is translated against English (same numbers as `lang:missing`, docs/ADMIN_PLAN.md item 14).
 */
final class TranslationProgress
{
    /**
     * @return list<array{locale: string, name: string, kind: string, translated: int, total: int, missing: int, percent: float}>
     */
    public static function all(): array
    {
        $en = self::load('en');
        $total = count($en);
        $rows = [];
        foreach (config()->array('x2key.locales') as $code => $meta) {
            if (! is_string($code) || $code === 'en') {
                continue;
            }
            $missing = count(array_diff_key($en, self::load($code)));
            $rows[] = [
                'locale' => $code,
                'name' => is_array($meta) && is_string($meta['name'] ?? null) ? $meta['name'] : $code,
                'kind' => is_array($meta) && ($meta['content'] ?? false) === true ? 'content' : 'interface',
                'translated' => $total - $missing,
                'total' => $total,
                'missing' => $missing,
                'percent' => $total === 0 ? 100.0 : round(($total - $missing) * 100 / $total, 1),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public static function load(string $code): array
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
