<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Development driver: writes SMS to the log. A real provider (Twilio, Vonage…) is chosen with D-decisions.
 */
final class LogSmsSender implements SmsSender
{
    public function send(string $e164Phone, string $message): void
    {
        Log::channel(config()->string('logging.default'))->info('SMS (log driver)', ['to' => $e164Phone, 'message' => $message]);
    }
}
