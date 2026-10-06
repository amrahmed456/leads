<?php

namespace App\Intelligence\Scoring;

/**
 * A 0-100 score with every point explained.
 */
final readonly class ScoreResult
{
    /**
     * @param  list<array{key: string, label: string, points: int}>  $breakdown
     */
    public function __construct(
        public int $score,
        public array $breakdown,
    ) {}

    /**
     * @param  list<array{key: string, label: string, points: int}>  $breakdown
     */
    public static function fromBreakdown(array $breakdown): self
    {
        return new self(max(0, min(100, array_sum(array_column($breakdown, 'points')))), $breakdown);
    }

    public function has(string $key): bool
    {
        return in_array($key, array_column($this->breakdown, 'key'), true);
    }
}
