<?php

namespace App\Intelligence\Eligibility;

use App\Enums\WebsiteSignal;
use App\Models\Business;
use App\Models\WebsiteAudit;

/**
 * The ways a business can be contacted, from its data and its website.
 */
final class ContactRoutes
{
    /**
     * @return list<string> Any of: phone, whatsapp, email, website, social
     */
    public function for(Business $business, ?WebsiteAudit $audit): array
    {
        $analyzed = $audit?->isAnalyzed() ? $audit : null;
        $social = array_diff_key($business->social_links ?? [], ['whatsapp' => true]) !== []
            || ($analyzed?->signalData(WebsiteSignal::SocialProfiles)['profiles'] ?? []) !== [];

        return array_keys(array_filter([
            'phone' => $business->phone !== null || $analyzed?->has_phone === true,
            'whatsapp' => isset($business->social_links['whatsapp']) || $analyzed?->has_whatsapp === true,
            'email' => $business->email !== null || $analyzed?->has_email === true,
            'website' => $business->website !== null && ! $audit?->showsBrokenWebsite(),
            'social' => $social,
        ]));
    }
}
