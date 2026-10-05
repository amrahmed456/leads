<?php

namespace App\Intelligence\Opportunities\Rules;

use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityContext;

/**
 * A website available in only one of Arabic and English, with no link to
 * a version in the other language.
 */
final class MultilingualWebsiteRule implements OpportunityRule
{
    public function evaluate(OpportunityContext $context): ?DetectedOpportunity
    {
        $audit = $context->analyzedAudit();

        if ($audit === null || $audit->isMultilingual() || (! $audit->has_arabic && ! $audit->has_english)) {
            return null;
        }

        $reasons = $audit->has_arabic
            ? ['The website is only in Arabic', 'English-speaking residents and visitors are not served']
            : ['The website is only in English', 'Arabic-speaking customers are not served in their language'];

        return new DetectedOpportunity(OpportunityType::MultilingualWebsite, OpportunityPriority::Medium, $reasons, 'single_language');
    }
}
