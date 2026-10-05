<?php

namespace App\Intelligence\Opportunities\Rules;

use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityContext;
use Illuminate\Support\Str;

/**
 * A business without a website. High priority when the business can be
 * contacted (phone, e-mail, WhatsApp or social media).
 */
final class WebsiteMissingRule implements OpportunityRule
{
    public function evaluate(OpportunityContext $context): ?DetectedOpportunity
    {
        if ($context->hasWebsite()) {
            return null;
        }

        $business = $context->business;
        $socialNetworks = array_keys(array_diff_key($business->social_links ?? [], ['whatsapp' => true]));
        $contacts = array_keys(array_filter([
            'phone' => $business->phone,
            'e-mail' => $business->email,
            'WhatsApp' => $business->social_links['whatsapp'] ?? null,
        ]));

        $reasons = ['Business has no website'];

        if ($socialNetworks !== []) {
            $reasons[] = 'Relies on '.implode(', ', array_map(Str::headline(...), $socialNetworks)).' instead of a website';
        }

        if ($business->latitude !== null || $business->address !== null) {
            $reasons[] = 'Has a verified physical location';
        }

        if ($contacts !== []) {
            $reasons[] = 'Contact information available ('.implode(', ', $contacts).')';
        }

        foreach ($context->needs as $capability => $priority) {
            if ($priority !== OpportunityPriority::Low) {
                $reasons[] = 'Would benefit from '.mb_strtolower(OpportunityType::from($capability)->label());
            }
        }

        $reachable = $contacts !== [] || $socialNetworks !== [];

        return new DetectedOpportunity(
            OpportunityType::WebsiteMissing,
            $reachable ? OpportunityPriority::High : OpportunityPriority::Medium,
            $reasons,
            'no_website',
        );
    }
}
