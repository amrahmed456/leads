<?php

namespace App\Intelligence\Outreach;

use App\Enums\Country;
use App\Enums\WebsiteSignal;
use App\Models\Business;
use App\Models\WebsiteAudit;

/**
 * Picks the number most likely to reach a business on WhatsApp.
 *
 * A number listed as WhatsApp comes first; otherwise a mobile number.
 * Landlines, unified (920) and toll-free numbers are skipped because a
 * wa.me link to them usually does not work.
 */
final class WhatsAppNumberResolver
{
    /**
     * @return array{number: string, source: string, confirmed: bool}|null
     */
    public function resolve(Business $business, ?WebsiteAudit $audit): ?array
    {
        $listed = $this->digits((string) parse_url((string) ($business->social_links['whatsapp'] ?? ''), PHP_URL_PATH));

        if ($listed !== null) {
            return ['number' => $listed, 'source' => 'WhatsApp listed in the business profile', 'confirmed' => true];
        }

        foreach ($audit?->signalData(WebsiteSignal::Whatsapp)['numbers'] ?? [] as $number) {
            if (($digits = $this->digits($number)) !== null) {
                return ['number' => $digits, 'source' => 'WhatsApp link on the website', 'confirmed' => true];
            }
        }

        if ($business->phone !== null && Country::isMobileNumber($business->phone)) {
            return ['number' => (string) $this->digits($business->phone), 'source' => 'Mobile number from the business profile', 'confirmed' => false];
        }

        foreach ($audit?->signalData(WebsiteSignal::Phone)['numbers'] ?? [] as $number) {
            if (Country::isMobileNumber($number)) {
                return ['number' => (string) $this->digits($number), 'source' => 'Mobile number on the website', 'confirmed' => false];
            }
        }

        return null;
    }

    private function digits(string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }
}
