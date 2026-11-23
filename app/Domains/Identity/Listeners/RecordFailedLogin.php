<?php

declare(strict_types=1);

namespace App\Domains\Identity\Listeners;

use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\DeviceCookie;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

/**
 * Failed attempts feed risk rules (failed logins per IP/device, spec 17.1).
 */
final class RecordFailedLogin
{
    public function __construct(private readonly Request $request) {}

    public function handle(Failed $event): void
    {
        $identifier = $this->request->input(Fortify::username());

        LoginEvent::query()->create([
            'user_id' => $event->user instanceof User ? $event->user->id : null,
            'identifier' => is_string($identifier) ? mb_substr($identifier, 0, 255) : null,
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'device_fingerprint' => DeviceCookie::fingerprint($this->request),
            'succeeded' => false,
        ]);
    }
}
