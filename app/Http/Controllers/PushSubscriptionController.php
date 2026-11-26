<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Announcements\Models\PushSubscription;
use App\Domains\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Save / remove this browser's web push subscription (ADR-037). */
final class PushSubscriptionController
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $endpoint = $request->string('endpoint')->toString();
        PushSubscription::query()->updateOrCreate(['endpoint_hash' => hash('sha256', $endpoint)], [
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'public_key' => $request->string('keys.p256dh')->toString(),
            'auth_token' => $request->string('keys.auth')->toString(),
            'content_encoding' => 'aes128gcm',
        ]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        PushSubscription::query()->where('user_id', $user->id)->where('endpoint_hash', hash('sha256', $request->string('endpoint')->toString()))->delete();

        return response()->json(['ok' => true]);
    }
}
