<?php

namespace App\Intelligence\Opportunities\Rules;

use App\Enums\OpportunityType;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityContext;

/**
 * A retail business whose website has no cart or checkout.
 */
final class EcommerceRule implements OpportunityRule
{
    public function evaluate(OpportunityContext $context): ?DetectedOpportunity
    {
        $priority = $context->need(OpportunityType::Ecommerce);
        $audit = $context->analyzedAudit();

        if ($priority === null || $audit === null || $audit->has_cart) {
            return null;
        }

        return new DetectedOpportunity(OpportunityType::Ecommerce, $priority, [
            $audit->has_ecommerce
                ? 'Products are presented, but there is no cart or checkout'
                : 'No online store found on the website',
            "{$context->categoryLabel()} commonly sell online",
        ], 'missing_online_store');
    }
}
