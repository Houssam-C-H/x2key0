<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Identity\Actions\ExportPersonalData;
use App\Domains\Identity\Models\User;
use App\Shared\Ops\HealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** `/health` for uptime monitors (spec M19 "alerts") and the personal data download (spec Part 23 "data export"). */
final class OpsController
{
    /** 200 when healthy, 503 otherwise; details only with `?token=` matching `HEALTH_TOKEN`. */
    public function health(Request $request, HealthCheck $health): JsonResponse
    {
        $checks = $health->run();
        $ok = HealthCheck::healthy($checks);
        $token = config('x2key.ops.health_token');
        $detailed = is_string($token) && $token !== '' && hash_equals($token, (string) $request->query('token', ''));

        return response()->json($detailed ? ['status' => $ok ? 'ok' : 'fail', 'checks' => $checks] : ['status' => $ok ? 'ok' : 'fail'], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /** Settings › Account › "Download my data": a JSON file (recent password required, rate-limited, audited). */
    public function export(Request $request, ExportPersonalData $export): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $export->handle($user);

        return response()->streamDownload(static function () use ($data): void {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }, 'x2key-data-'.$user->public_id.'.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
