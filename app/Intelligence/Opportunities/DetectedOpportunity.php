<?php

namespace App\Intelligence\Opportunities;

use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;

final readonly class DetectedOpportunity
{
    /**
     * @param  list<string>  $reasons  Why the opportunity was detected, in plain language
     * @param  string  $rule  Machine name of the rule that detected it
     */
    public function __construct(
        public OpportunityType $type,
        public OpportunityPriority $priority,
        public array $reasons,
        public string $rule,
    ) {}
}
