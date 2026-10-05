<?php

namespace App\Intelligence\Opportunities\Rules;

use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityContext;

/**
 * A website without WhatsApp contact for a business that has a phone number.
 * More valuable when WhatsApp could also take bookings or orders.
 */
final class WhatsappIntegrationRule implements OpportunityRule
{
    public function evaluate(OpportunityContext $context): ?DetectedOpportunity
    {
        $audit = $context->analyzedAudit();

        if ($audit === null || $audit->has_whatsapp || ($context->business->phone === null && ! $audit->has_phone)) {
            return null;
        }

        $transactional = $context->need(OpportunityType::OnlineBooking) !== null || $context->need(OpportunityType::OnlineOrdering) !== null;

        return new DetectedOpportunity(
            OpportunityType::WhatsappIntegration,
            $transactional ? OpportunityPriority::Medium : OpportunityPriority::Low,
            array_values(array_filter([
                'No WhatsApp button or link on the website',
                'A phone number is available that could be connected to WhatsApp',
                $transactional ? 'WhatsApp could also take bookings or orders' : null,
            ])),
            'missing_whatsapp',
        );
    }
}
