<?php

namespace App\Intelligence\Opportunities;

use App\Intelligence\CategoryNeeds;
use App\Intelligence\Opportunities\Rules\EcommerceRule;
use App\Intelligence\Opportunities\Rules\MobileImprovementRule;
use App\Intelligence\Opportunities\Rules\MultilingualWebsiteRule;
use App\Intelligence\Opportunities\Rules\OnlineBookingRule;
use App\Intelligence\Opportunities\Rules\OnlineOrderingRule;
use App\Intelligence\Opportunities\Rules\OpportunityRule;
use App\Intelligence\Opportunities\Rules\WebsiteMissingRule;
use App\Intelligence\Opportunities\Rules\WebsiteRedesignRule;
use App\Intelligence\Opportunities\Rules\WhatsappIntegrationRule;
use App\Models\Business;
use App\Models\WebsiteAudit;

/**
 * Detects opportunities for a business with deterministic rules. To add an
 * opportunity, implement OpportunityRule and add it to the constructor.
 */
final class OpportunityDetector
{
    /** @var list<OpportunityRule> */
    private readonly array $rules;

    public function __construct(
        private readonly CategoryNeeds $needs,
        WebsiteMissingRule $websiteMissing,
        WebsiteRedesignRule $websiteRedesign,
        MobileImprovementRule $mobileImprovement,
        OnlineBookingRule $onlineBooking,
        OnlineOrderingRule $onlineOrdering,
        EcommerceRule $ecommerce,
        WhatsappIntegrationRule $whatsappIntegration,
        MultilingualWebsiteRule $multilingualWebsite,
    ) {
        $this->rules = [
            $websiteMissing, $websiteRedesign, $mobileImprovement, $onlineBooking,
            $onlineOrdering, $ecommerce, $whatsappIntegration, $multilingualWebsite,
        ];
    }

    /**
     * @param  WebsiteAudit|null  $audit  Latest finished audit of the business's current website
     * @return list<DetectedOpportunity> Highest priority first
     */
    public function detect(Business $business, ?WebsiteAudit $audit): array
    {
        $context = new OpportunityContext($business, $audit, $this->needs->forBusiness($business));

        $found = array_values(array_filter(array_map(
            fn (OpportunityRule $rule): ?DetectedOpportunity => $rule->evaluate($context),
            $this->rules,
        )));

        usort($found, fn (DetectedOpportunity $a, DetectedOpportunity $b): int => $b->priority->rank() <=> $a->priority->rank());

        return $found;
    }
}
