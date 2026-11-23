<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Long-lived random device id (encrypted cookie). Only its SHA-256 is stored server-side.
 * Used for "new device" alerts and later for risk rules (same-device affiliate abuse, etc.).
 */
final class DeviceCookie
{
    public const string NAME = 'x2k_device';

    private const int FIVE_YEARS_MINUTES = 60 * 24 * 365 * 5;

    /**
     * Returns the fingerprint for this request, queueing a new cookie if the browser has none.
     */
    public static function fingerprint(Request $request): string
    {
        $id = $request->cookie(self::NAME);

        if (! is_string($id) || strlen($id) < 26) {
            $id = (string) Str::ulid().Str::random(16);
            Cookie::queue(Cookie::make(self::NAME, $id, self::FIVE_YEARS_MINUTES, httpOnly: true));
            $request->cookies->set(self::NAME, $id);
        }

        return hash('sha256', $id);
    }

    /** Short human label from the user agent, e.g. "Chrome on Windows". */
    public static function label(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unknown OS',
        };

        return "{$browser} on {$os}";
    }
}
