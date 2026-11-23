<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Accepts only same-site relative paths for "return to" parameters (no open redirects).
 */
final class SafePath
{
    public static function from(mixed $value, string $fallback): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2000) {
            return $fallback;
        }

        if (! str_starts_with($value, '/') || str_starts_with($value, '//') || str_contains($value, '\\')) {
            return $fallback;
        }

        return $value;
    }
}
