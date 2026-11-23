<?php

declare(strict_types=1);

namespace App\Domains\Risk\Support;

use App\Domains\Risk\Enums\RiskDecision;

/** Combined outcome of the risk rules: the strictest decision, the summed score, and every rule that fired. */
final readonly class RiskResult
{
    /**
     * @param  list<array{rule: string, decision: RiskDecision, score: int}>  $hits
     */
    public function __construct(
        public RiskDecision $decision,
        public int $score,
        public array $hits,
    ) {}

    public static function pass(): self
    {
        return new self(RiskDecision::Pass, 0, []);
    }

    public function with(string $rule, RiskDecision $decision, int $score): self
    {
        return new self($this->decision->max($decision), min(1000, $this->score + $score), [...$this->hits, ['rule' => $rule, 'decision' => $decision, 'score' => $score]]);
    }

    /**
     * @return list<string>
     */
    public function rules(): array
    {
        return array_map(static fn (array $h): string => $h['rule'], $this->hits);
    }
}
