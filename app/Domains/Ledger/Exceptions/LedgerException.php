<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Exceptions;

use RuntimeException;

/** A journal was refused: unbalanced, empty, or its idempotency key belongs to another journal type. */
final class LedgerException extends RuntimeException {}
