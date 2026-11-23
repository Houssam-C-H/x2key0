<?php

declare(strict_types=1);

namespace App\Domains\Violations\Enums;

/**
 * Violation status (spec 6.7): open → appealed → upheld | revoked; open → upheld once the appeal window ends.
 * Only upheld, unexpired violations count towards penalties.
 */
enum ViolationStatus: string
{
    case Open = 'open';
    case Appealed = 'appealed';
    case Upheld = 'upheld';
    case Revoked = 'revoked';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Appealed, self::Upheld, self::Revoked],
            self::Appealed => [self::Upheld, self::Revoked],
            self::Upheld, self::Revoked => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }
}
