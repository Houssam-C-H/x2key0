<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Jobs;

use App\Domains\Announcements\Support\WebPushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Queued web push to one user's devices (ADR-037). */
final class SendWebPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array{title: string, body: string, url: string}  $message
     */
    public function __construct(public readonly int $userId, public readonly array $message) {}

    public function handle(WebPushSender $sender): void
    {
        $sender->send($this->userId, $this->message);
    }
}
