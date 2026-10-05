<?php

namespace App\Intelligence\Scoring;

use App\Enums\LeadGrade;

final readonly class LeadScoreResult
{
    /**
     * @param  int  $score  0-100
     * @param  list<array{key: string, label: string, points: int}>  $breakdown  Every rule that contributed, with its points
     * @param  string  $version  Version of the scoring rules
     */
    public function __construct(
        public int $score,
        public LeadGrade $grade,
        public array $breakdown,
        public string $version,
    ) {}
}
