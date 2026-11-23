<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

interface SmsSender
{
    public function send(string $e164Phone, string $message): void;
}
