<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Support;

use App\Shared\Support\Settings;
use Carbon\CarbonInterface;

/** Online status (spec 12.1): a user is online when seen within `online_threshold_seconds` (heartbeat = any request, ≤ 60 s). */
final class Presence
{
    public function __construct(private readonly Settings $settings) {}

    public function online(?CarbonInterface $lastSeen): bool
    {
        return $lastSeen !== null && $lastSeen->greaterThanOrEqualTo(now()->subSeconds($this->settings->int('online_threshold_seconds')));
    }
}
