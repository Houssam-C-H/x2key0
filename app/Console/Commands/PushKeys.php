<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** `push:keys`: prints a new VAPID key pair for .env (web push, ADR-037). Generate once; changing keys drops all subscriptions. */
final class PushKeys extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Generate VAPID keys for web push (put them in .env once)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('VAPID_PUBLIC_KEY='.(is_string($keys['publicKey'] ?? null) ? $keys['publicKey'] : ''));
        $this->line('VAPID_PRIVATE_KEY='.(is_string($keys['privateKey'] ?? null) ? $keys['privateKey'] : ''));

        return self::SUCCESS;
    }
}
