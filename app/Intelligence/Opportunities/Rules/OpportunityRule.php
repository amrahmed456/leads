<?php

namespace App\Intelligence\Opportunities\Rules;

use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityContext;

/**
 * A deterministic rule that detects one kind of opportunity.
 */
interface OpportunityRule
{
    public function evaluate(OpportunityContext $context): ?DetectedOpportunity;
}
