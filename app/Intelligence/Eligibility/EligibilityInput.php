<?php

namespace App\Intelligence\Eligibility;

use App\Identity\EntityClassification;
use App\Identity\Evidence\ReconciliationResult;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Scoring\ScoreResult;
use App\Models\Business;

/**
 * Everything the eligibility decision looks at.
 */
final readonly class EligibilityInput
{
    /**
     * @param  list<DetectedOpportunity>  $opportunities
     * @param  list<string>  $contactRoutes
     */
    public function __construct(
        public Business $business,
        public EntityClassification $entity,
        public ReconciliationResult $evidence,
        public ScoreResult $dataConfidence,
        public ScoreResult $businessFit,
        public ScoreResult $opportunity,
        public array $opportunities,
        public array $contactRoutes,
    ) {}
}
