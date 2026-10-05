<?php

namespace Tests\Feature\Intelligence;

use App\Enums\AuditFailure;
use App\Enums\BusinessCategory;
use App\Enums\LeadGrade;
use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Scoring\LeadScoringService;
use App\Models\Business;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reachable_high_value_business_without_a_website_is_a_hot_lead(): void
    {
        $business = Business::factory()->create([
            'website' => null, 'category' => BusinessCategory::Dentist, 'phone' => '+966501234567',
            'social_links' => ['instagram' => 'https://www.instagram.com/smile'],
        ]);

        $result = $this->scoring()->score($business, null, [$this->opportunity(OpportunityType::WebsiteMissing, OpportunityPriority::High)]);

        $this->assertSame([
            ['key' => 'website_missing', 'label' => 'No website', 'points' => 40],
            ['key' => 'latent_need_high', 'label' => 'Category needs online booking', 'points' => 8],
            ['key' => 'phone', 'label' => 'Phone number available', 'points' => 8],
            ['key' => 'social', 'label' => 'Active on Instagram', 'points' => 5],
            ['key' => 'location', 'label' => 'Verified physical location', 'points' => 2],
            ['key' => 'category_high', 'label' => 'High-value category: Dentists', 'points' => 10],
        ], $result->breakdown);
        $this->assertSame(73, $result->score);
        $this->assertSame(LeadGrade::Hot, $result->grade);
        $this->assertSame('1', $result->version);
    }

    public function test_a_business_with_a_strong_website_is_a_cold_lead(): void
    {
        $business = Business::factory()->create(['website' => 'https://smile.ae', 'category' => BusinessCategory::Dentist, 'phone' => '+97142345678', 'email' => 'hi@smile.ae']);
        $audit = WebsiteAudit::factory()->create(['business_id' => $business, 'score' => 85]);

        $result = $this->scoring()->score($business, $audit, []);

        $this->assertSame(['website_strong', 'phone', 'email', 'location', 'category_high'], array_column($result->breakdown, 'key'));
        $this->assertSame('Strong website (audit score 85/100)', $result->breakdown[0]['label']);
        $this->assertSame(24, $result->score);
        $this->assertSame(LeadGrade::Cold, $result->grade);
    }

    public function test_opportunity_points_are_capped(): void
    {
        $business = Business::factory()->create(['website' => 'https://example.com', 'category' => BusinessCategory::Restaurant]);
        $audit = WebsiteAudit::factory()->create(['business_id' => $business, 'score' => 35]);

        $result = $this->scoring()->score($business, $audit, [
            $this->opportunity(OpportunityType::OnlineOrdering, OpportunityPriority::High),
            $this->opportunity(OpportunityType::OnlineBooking, OpportunityPriority::High),
            $this->opportunity(OpportunityType::WebsiteRedesign, OpportunityPriority::High),
            $this->opportunity(OpportunityType::MultilingualWebsite, OpportunityPriority::Medium),
        ]);

        $this->assertSame([10, 10, 5, 0], array_column(array_values(array_filter(
            $result->breakdown,
            fn (array $item): bool => str_starts_with($item['key'], 'opportunity_'),
        )), 'points'));
        $this->assertContains(['key' => 'website_weak', 'label' => 'Weak website (audit score 35/100)', 'points' => 15], $result->breakdown);
        $this->assertContains(['key' => 'no_contact', 'label' => 'No phone, e-mail, WhatsApp or social profile found', 'points' => -15], $result->breakdown);
        $this->assertSame(32, $result->score);
    }

    public function test_a_broken_website_is_not_counted_twice(): void
    {
        $business = Business::factory()->create(['website' => 'https://down.sa', 'category' => BusinessCategory::Dentist, 'phone' => '+966501234567']);
        $audit = WebsiteAudit::factory()->failed(AuditFailure::ConnectionFailed)->create(['business_id' => $business]);

        $result = $this->scoring()->score($business, $audit, [$this->opportunity(OpportunityType::WebsiteRedesign, OpportunityPriority::High)]);

        $this->assertSame(['website_broken', 'phone', 'location', 'category_high'], array_column($result->breakdown, 'key'));
        $this->assertSame(50, $result->score);
        $this->assertSame(LeadGrade::Warm, $result->grade);
    }

    public function test_the_score_never_drops_below_zero(): void
    {
        $business = Business::factory()->create([
            'website' => 'https://example.com', 'category' => null, 'phone' => null, 'email' => null,
            'social_links' => null, 'address' => null, 'latitude' => null, 'longitude' => null,
        ]);

        $result = $this->scoring()->score($business, null, []);

        $this->assertSame(['website_not_audited', 'no_contact'], array_column($result->breakdown, 'key'));
        $this->assertSame(0, $result->score);
    }

    public function test_contact_details_found_on_the_website_count(): void
    {
        $business = Business::factory()->create(['website' => 'https://example.com', 'phone' => null, 'email' => null]);
        $audit = WebsiteAudit::factory()->create(['business_id' => $business, 'signals' => [
            'phone' => ['confidence' => 'high', 'evidence' => [], 'data' => ['numbers' => ['+966112345678']]],
        ]]);

        $result = $this->scoring()->score($business, $audit, []);

        $this->assertContains(['key' => 'phone', 'label' => 'Phone number found on the website', 'points' => 8], $result->breakdown);
    }

    private function opportunity(OpportunityType $type, OpportunityPriority $priority): DetectedOpportunity
    {
        return new DetectedOpportunity($type, $priority, ['Reason'], 'test');
    }

    private function scoring(): LeadScoringService
    {
        return $this->app->make(LeadScoringService::class);
    }
}
