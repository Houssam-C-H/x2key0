<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers (spec Part 23 "Web: CSP, HSTS, secure cookies"):
 *
 * - Content-Security-Policy on the site: scripts only from this origin with a per-request nonce (Vite tags carry
 *   it), no inline event handlers, no plugins, no framing (`frame-ancestors 'none'`), forms and base URI to self;
 *   connections to self plus the Reverb websocket and, in local, the Vite dev server. Inline JSON (Inertia page
 *   data, JSON-LD) is not executed and needs no exception. The Filament admin (/admin, /livewire) uses Alpine,
 *   which needs `unsafe-eval`, so it gets a separate, looser policy that still forbids framing and plugins;
 * - HSTS (1 year, subdomains) in production over HTTPS; nosniff; strict-origin referrer; a Permissions-Policy that
 *   switches off camera, microphone, geolocation and payment APIs the site does not use;
 * - `x2key.security.csp_report_only` sends the CSP as Report-Only (to trial a change without breaking pages).
 */
final class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (app()->isProduction() && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $csp = self::isAdmin($request) ? self::adminPolicy() : self::sitePolicy($nonce);
        $headers->set(config('x2key.security.csp_report_only') === true ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $csp);

        return $response;
    }

    public static function sitePolicy(string $nonce): string
    {
        $dev = self::devOrigins();
        $connect = trim("'self' ".self::reverbOrigins().' '.$dev['connect']);

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' ".$dev['script'],
            "style-src 'self' 'unsafe-inline' ".$dev['script'],
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "connect-src {$connect}",
            "media-src 'self' https:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    public static function adminPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https://fonts.gstatic.com",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    private static function isAdmin(Request $request): bool
    {
        return $request->is('admin', 'admin/*', 'livewire/*', 'livewire-*/*', 'filament/*');
    }

    /** Reverb websocket origins the browser connects to (live chat, ADR-021): the public address when set. */
    private static function reverbOrigins(): string
    {
        $public = config('x2key.realtime.public_host');
        $host = is_string($public) && $public !== '' ? $public : config('reverb.apps.apps.0.options.host');
        if (! is_string($host) || $host === '') {
            return '';
        }
        $port = is_string($public) && $public !== '' ? config('x2key.realtime.public_port') : config('reverb.apps.apps.0.options.port');
        $suffix = is_scalar($port) && (string) $port !== '' ? ':'.$port : '';

        return "ws://{$host}{$suffix} wss://{$host}{$suffix}";
    }

    /**
     * The Vite dev server (hot file) in local only.
     *
     * @return array{script: string, connect: string}
     */
    private static function devOrigins(): array
    {
        $hot = public_path('hot');
        if (app()->isProduction() || ! is_file($hot)) {
            return ['script' => '', 'connect' => ''];
        }
        $url = trim((string) file_get_contents($hot));
        $ws = (string) preg_replace('#^http#', 'ws', $url);

        return ['script' => $url, 'connect' => "{$url} {$ws}"];
    }
}
