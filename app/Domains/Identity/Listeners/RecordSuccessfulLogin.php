<?php

declare(strict_types=1);

namespace App\Domains\Identity\Listeners;

use App\Domains\Identity\Models\LoginEvent;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserDevice;
use App\Domains\Identity\Notifications\NewDeviceLogin;
use App\Domains\Identity\Support\DeviceCookie;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * On login: log the event, recognise the device, alert by email on a new device
 * (not on the very first device of an account).
 */
final class RecordSuccessfulLogin
{
    public function __construct(private readonly Request $request) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $fingerprint = DeviceCookie::fingerprint($this->request);
        $userAgent = $this->request->userAgent();
        $hadDevices = $user->devices()->exists();

        $device = UserDevice::query()->firstOrNew(['user_id' => $user->id, 'fingerprint' => $fingerprint]);
        $isNew = ! $device->exists;
        $device->name ??= DeviceCookie::label($userAgent);
        $device->last_seen_at = now();
        $device->save();

        LoginEvent::query()->create([
            'user_id' => $user->id,
            'ip' => $this->request->ip(),
            'user_agent' => $userAgent,
            'device_fingerprint' => $fingerprint,
            'succeeded' => true,
        ]);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        if ($isNew && $hadDevices) {
            $user->notify(new NewDeviceLogin($device->name ?? 'Unknown device', (string) $this->request->ip()));
        }
    }
}
