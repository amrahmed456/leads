<?php

namespace App\Intelligence\Eligibility;

use App\Enums\LeadStatus;

final readonly class EligibilityResult
{
    /**
     * @param  list<array{code: string, label: string}>  $reasons  Why the business has this status, most important first
     * @param  int  $score  Composite lead score (0 when disqualified)
     * @param  list<array{key: string, label: string, points: int}>  $breakdown  How the lead score was composed
     */
    public function __construct(
        public LeadStatus $status,
        public array $reasons,
        public int $score,
        public array $breakdown,
    ) {}

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_column($this->reasons, 'code');
    }
}
