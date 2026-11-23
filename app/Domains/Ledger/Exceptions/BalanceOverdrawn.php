<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Exceptions;

use RuntimeException;

/** The journal would take a user-owned balance below zero (invariant 4.3 #2); nothing was posted. */
final class BalanceOverdrawn extends RuntimeException {}
