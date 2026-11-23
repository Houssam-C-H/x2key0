<?php

declare(strict_types=1);

namespace App\Domains\Payments\Exceptions;

use RuntimeException;

/** Webhook rejected: bad signature or unreadable body. Never processed. */
final class InvalidWebhook extends RuntimeException {}
