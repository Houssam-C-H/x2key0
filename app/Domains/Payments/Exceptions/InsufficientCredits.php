<?php

declare(strict_types=1);

namespace App\Domains\Payments\Exceptions;

use RuntimeException;

/** The Credits Balance no longer covers the credits part of the order. */
final class InsufficientCredits extends RuntimeException {}
