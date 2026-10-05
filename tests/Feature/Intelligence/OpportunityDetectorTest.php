<?php

namespace Tests\Feature\Intelligence;

use App\Enums\AuditFailure;
use App\Enums\BusinessCategory;
use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Opportunities\OpportunityDetector;
use App\Models\Business;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reachable_business_without_a_website_is_a_high_priority_website_opportunity(): void
    {
        $business = Business::factory()->create([
            'website' => null, 'category' => BusinessCategory::Dentist, 'phone' => '+966501234567',
            'social_links' => ['instagram' => 'https://www.instagram.com/smile'],
        ]);

        [$opportunity] = $this->detect($business);

        $this->assertSame(OpportunityType::WebsiteMissing, $opportunity->type);
        $this->assertSame(OpportunityPriority::High, $opportunity->priority);
        $this->assertSame([
            'Business has no website',
            'Relies on Instagram instead of a website',
            'Has a verified physical location',
            'Contact information available (phone)',
            'Would benefit from online booking',
        ], $opportunity->reasons);
    }

    public function test_a_business_without_a_website_or_contact_details_is_medium_priority(): void
    {
        $business = Business::factory()->create(['website' => null, 'phone' => null, 'email' => null, 'social_links' => null]);

        $this->assertSame(OpportunityPriority::Medium, $this->detect($business)[0]->priority);
    }

    public function test_a_restaurant_selling_only_through_delivery_apps_needs_its_own_online_ordering(): void
    {
        [$business, $audit] = $this->audited(BusinessCategory::Restaurant, ['signals' => [
            'delivery_apps' => ['confidence' => 'high', 'evidence' => ['Linked to Talabat'], 'data' => ['platforms' => ['Talabat']]],
            'online_ordering' => ['confidence' => 'none', 'evidence' => [], 'data' => ['menu' => true]],
        ]]);

        $found = $this->detect($business, $audit);

        $this->assertSame([OpportunityType::OnlineOrdering, OpportunityType::OnlineBooking], $this->types($found), 'Highest priority first.');
        $this->assertSame(OpportunityPriority::High, $found[0]->priority);
        $this->assertSame([
            'No online ordering found on the website',
            'Sells through Talabat; direct ordering would avoid marketplace commissions',
            'Shows a menu, but it cannot be ordered from',
        ], $found[0]->reasons);
    }

    public function test_a_restaurant_with_its_own_ordering_app_is_a_lower_priority_web_ordering_lead(): void
    {
        [$business, $audit] = $this->audited(BusinessCategory::Restaurant, ['signals' => [
            'mobile_app' => ['confidence' => 'high', 'evidence' => ['Links to its app on the App Store'], 'data' => ['stores' => ['the App Store']]],
        ]]);

        $ordering = collect($this->detect($business, $audit))->firstWhere('type', OpportunityType::OnlineOrdering);

        $this->assertSame(OpportunityPriority::Low, $ordering->priority);
        $this->assertContains('Has its own mobile app; web ordering would also reach customers without the app', $ordering->reasons);
    }

    public function test_fast_food_outlets_are_not_expected_to_take_table_bookings(): void
    {
        [$business, $audit] = $this->audited(BusinessCategory::Restaurant);
        $business->update(['type' => 'fast_food']);

        $this->assertSame([OpportunityType::OnlineOrdering], $this->types($this->detect($business, $audit)));
    }

    public function test_a_clinic_website_without_online_booking_is_a_booking_opportunity(): void
    {
        [$business, $audit] = $this->audited(BusinessCategory::Dentist, ['has_phone' => true, 'has_whatsapp' => true]);

        $found = $this->detect($business, $audit);

        $this->assertSame([OpportunityType::OnlineBooking], $this->types($found));
        $this->assertSame(OpportunityPriority::High, $found[0]->priority);
        $this->assertSame([
            'No online booking found on the website',
            'Dentists typically take bookings online',
            'Customers have to call or message to book',
        ], $found[0]->reasons);
    }

    public function test_a_store_showing_products_without_a_cart_is_an_ecommerce_opportunity(): void
    {
        [$business, $audit] = $this->audited(BusinessCategory::Fashion, ['has_ecommerce' => true, 'has_cart' => false]);

        $found = $this->detect($business, $audit);

        $this->assertSame([OpportunityType::Ecommerce], $this->types($found));
        $this->assertSame(['Products are presented, but there is no cart or checkout', 'Clothing & fashion commonly sell online'], $found[0]->reasons);
    }

    public function test_a_website_without_a_mobile_viewport_needs_mobile_improvement(): void
    {
        [$business, $audit] = $this->audited(BusinessCategory::Contractors, ['has_viewport' => false]);

        $found = $this->detect($business, $audit);

        $this->assertSame([OpportunityType::MobileImprovement], $this->types($found));
        $this->assertSame(OpportunityPriority::Medium, $found[0]->priority);
    }

    public function test_an_outdated_insecure_low_scoring_website_needs_a_redesign(): void
    {
        [$business, $audit] = $this->audited(BusinessCategory::Contractors, [
            'is_https' => false,
            'score' => 30,
            'signals' => ['outdated_content' => ['confidence' => 'medium', 'evidence' => ['Copyright notice last updated in 2017'], 'data' => []]],
        ]);

        $found = $this->detect($business, $audit);

        $this->assertSame([OpportunityType::WebsiteRedesign], $this->types($found));
        $this->assertSame(OpportunityPriority::High, $found[0]->priority);
        $this->assertSame(['Not served over HTTPS', 'Copyright notice last updated in 2017', 'Low website audit score (30/100)'], $found[0]->reasons);
    }

    public function test_a_website_that_is_down_needs_a_redesign_and_nothing_else_is_assumed(): void
    {
        $business = Business::factory()->create(['website' => 'https://down.sa', 'category' => BusinessCategory::Dentist, 'phone' => '+966501234567']);
        $audit = WebsiteAudit::factory()->failed(AuditFailure::DnsFailure)->create(['business_id' => $business]);

        $found = $this->detect($business, $audit);

        $this->assertSame([OpportunityType::WebsiteRedesign], $this->types($found));
        $this->assertSame(OpportunityPriority::High, $found[0]->priority);
        $this->assertSame('broken_website', $found[0]->rule);
        $this->assertSame('The listed website is not working: the domain name does not resolve', $found[0]->reasons[0]);
    }

    public function test_an_audit_that_was_blocked_says_nothing_about_missing_features(): void
    {
        $business = Business::factory()->create(['website' => 'https://blocked.sa', 'category' => BusinessCategory::Dentist, 'phone' => '+966501234567']);
        $audit = WebsiteAudit::factory()->failed(AuditFailure::AccessDenied)->create(['business_id' => $business]);

        $this->assertSame([], $this->detect($business, $audit));
    }

    public function test_a_single_language_website_is_a_multilingual_opportunity(): void
    {
        [$business, $audit] = $this->audited(BusinessCategory::Contractors, ['has_english' => false]);

        $found = $this->detect($business, $audit);

        $this->assertSame([OpportunityType::MultilingualWebsite], $this->types($found));
        $this->assertSame(['The website is only in Arabic', 'English-speaking residents and visitors are not served'], $found[0]->reasons);
    }

    public function test_a_website_without_whatsapp_for_a_business_with_a_phone_is_a_whatsapp_opportunity(): void
    {
        [$business, $audit] = $this->audited(BusinessCategory::Contractors);
        $business->update(['phone' => '+966112345678']);

        $found = $this->detect($business, $audit);

        $this->assertSame([OpportunityType::WhatsappIntegration], $this->types($found));
        $this->assertSame(OpportunityPriority::Low, $found[0]->priority);
    }

    public function test_a_complete_website_has_no_opportunities(): void
    {
        [$business, $audit] = $this->audited(BusinessCategory::Dentist, ['has_booking' => true, 'has_whatsapp' => true, 'has_phone' => true, 'score' => 90]);

        $this->assertSame([], $this->detect($business, $audit));
    }

    /**
     * A business with a completed audit that found a basic, bilingual, mobile-ready site.
     *
     * @param  array<string, mixed>  $audit
     * @return array{0: Business, 1: WebsiteAudit}
     */
    private function audited(BusinessCategory $category, array $audit = []): array
    {
        $business = Business::factory()->create(['website' => 'https://example.com', 'category' => $category, 'phone' => null]);

        return [$business, WebsiteAudit::factory()->create(['business_id' => $business, ...$audit])];
    }

    /**
     * @return list<DetectedOpportunity>
     */
    private function detect(Business $business, ?WebsiteAudit $audit = null): array
    {
        return $this->app->make(OpportunityDetector::class)->detect($business, $audit);
    }

    /**
     * @param  list<DetectedOpportunity>  $opportunities
     * @return list<OpportunityType>
     */
    private function types(array $opportunities): array
    {
        return array_map(fn (DetectedOpportunity $opportunity): OpportunityType => $opportunity->type, $opportunities);
    }
}
