<?php

namespace App\Intelligence\Opportunities\Rules;

use App\Enums\Confidence;
use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Enums\WebsiteSignal;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityContext;

/**
 * A food or grocery business whose website has no direct online ordering.
 * Selling only through delivery apps makes it a stronger opportunity.
 */
final class OnlineOrderingRule implements OpportunityRule
{
    public function evaluate(OpportunityContext $context): ?DetectedOpportunity
    {
        $priority = $context->need(OpportunityType::OnlineOrdering);
        $audit = $context->analyzedAudit();

        if ($priority === null || $audit === null || $audit->has_online_ordering || $audit->has_cart) {
            return null;
        }

        $reasons = ['No online ordering found on the website'];
        $apps = $audit->signalData(WebsiteSignal::DeliveryApps)['platforms'] ?? [];

        if ($apps !== []) {
            $reasons[] = 'Sells through '.implode(', ', $apps).'; direct ordering would avoid marketplace commissions';
            $priority = OpportunityPriority::High;
        }

        // Customers can already order in the business's own app; web ordering is a smaller gap.
        if ($audit->signalConfidence(WebsiteSignal::MobileApp)->atLeast(Confidence::Medium)) {
            $reasons[] = 'Has its own mobile app; web ordering would also reach customers without the app';
            $priority = OpportunityPriority::Low;
        }

        if ($audit->signalData(WebsiteSignal::OnlineOrdering)['menu'] ?? false) {
            $reasons[] = 'Shows a menu, but it cannot be ordered from';
        }

        return new DetectedOpportunity(OpportunityType::OnlineOrdering, $priority, $reasons, 'missing_online_ordering');
    }
}
