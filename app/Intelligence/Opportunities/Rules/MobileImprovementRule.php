<?php

namespace App\Intelligence\Opportunities\Rules;

use App\Enums\Confidence;
use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Enums\WebsiteSignal;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityContext;

/**
 * A website without a mobile viewport.
 */
final class MobileImprovementRule implements OpportunityRule
{
    public function evaluate(OpportunityContext $context): ?DetectedOpportunity
    {
        $audit = $context->analyzedAudit();

        if ($audit === null || $audit->has_viewport) {
            return null;
        }

        $reasons = ['No mobile viewport configured, so phones likely show a shrunken desktop layout'];

        if ($audit->signalConfidence(WebsiteSignal::ResponsiveDesign) === Confidence::None) {
            $reasons[] = 'No responsive design signals found on the homepage';
        }

        return new DetectedOpportunity(OpportunityType::MobileImprovement, OpportunityPriority::Medium, $reasons, 'missing_viewport');
    }
}
