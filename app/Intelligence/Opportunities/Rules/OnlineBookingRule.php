<?php

namespace App\Intelligence\Opportunities\Rules;

use App\Enums\Confidence;
use App\Enums\OpportunityType;
use App\Enums\WebsiteSignal;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityContext;

/**
 * A business whose category takes bookings (clinics, salons, hotels...)
 * but whose website has no online booking.
 */
final class OnlineBookingRule implements OpportunityRule
{
    public function evaluate(OpportunityContext $context): ?DetectedOpportunity
    {
        $priority = $context->need(OpportunityType::OnlineBooking);
        $audit = $context->analyzedAudit();

        if ($priority === null || $audit === null || $audit->has_booking) {
            return null;
        }

        $reasons = ['No online booking found on the website', "{$context->categoryLabel()} typically take bookings online"];

        if ($audit->signalConfidence(WebsiteSignal::Booking) === Confidence::Low) {
            $reasons[] = 'Booking is mentioned, but visitors cannot book online';
        }

        if ($audit->has_phone || $audit->has_whatsapp) {
            $reasons[] = 'Customers have to call or message to book';
        }

        return new DetectedOpportunity(OpportunityType::OnlineBooking, $priority, $reasons, 'missing_online_booking');
    }
}
