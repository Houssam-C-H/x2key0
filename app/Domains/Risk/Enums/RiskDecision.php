<?php

declare(strict_types=1);

namespace App\Domains\Risk\Enums;

/** Outcome of the risk rules at checkout and capture (spec 17.1). */
enum RiskDecision: string
{
    case Pass = 'pass';
    case Review = 'review';
    case Block = 'block';

    public function weight(): int
    {
        return match ($this) {
            self::Pass => 0,
            self::Review => 1,
            self::Block => 2,
        };
    }

    public function max(self $other): self
    {
        return $other->weight() > $this->weight() ? $other : $this;
    }
}
