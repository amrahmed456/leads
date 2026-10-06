<?php

namespace Tests\Feature\Intelligence;

use App\Enums\AuditFailure;
use App\Enums\BusinessCategory;
use App\Enums\OpportunityPriority;
use App\Enums\OpportunityType;
use App\Intelligence\Opportunities\DetectedOpportunity;
use App\Intelligence\Scoring\OpportunityScoringService;
use App\Models\Business;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_business_without_a_website_in_a_category_with_online_needs_has_a_high_opportunity(): void
    {
        $business = Business::factory()->create(['website' => null, 'category' => BusinessCategory::Dentist, 'phone' => '+966501234567']);

        $result = $this->scoring()->score($business, null, [$this->opportunity(OpportunityType::WebsiteMissing, OpportunityPriority::High)]);

        $this->assertSame([
            ['key' => 'website_missing', 'label' => 'No website', 'points' => 45],
            ['key' => 'latent_need_high', 'label' => 'Category needs online booking', 'points' => 12],
        ], $result->breakdown);
        $this->assertSame(57, $result->score);
    }

    public function test_reachability_and_category_are_not_part_of_the_opportunity(): void
    {
        $business = Business::factory()->create(['website' => null, 'category' => BusinessCategory::Contractors, 'phone' => '+966501234567', 'email' => 'a@b.sa']);

        $this->assertSame(['website_missing'], array_column($this->scoring()->score($business, null, [])->breakdown, 'key'));
    }

    public function test_a_strong_website_without_high_priority_gaps_lowers_the_opportunity(): void
    {
        $business = Business::factory()->create(['website' => 'https://smile.ae', 'category' => BusinessCategory::Dentist]);
        $audit = WebsiteAudit::factory()->create(['business_id' => $business, 'score' => 85]);

        $result = $this->scoring()->score($business, $audit, [$this->opportunity(OpportunityType::WhatsappIntegration, OpportunityPriority::Medium)]);

        $this->assertSame(['website_strong', 'opportunity_whatsapp_integration', 'strong_digital_presence'], array_column($result->breakdown, 'key'));
        $this->assertSame(0, $result->score, '9 - 25, never below zero.');
    }

    public function test_a_high_priority_gap_cancels_the_strong_presence_penalty(): void
    {
        $business = Business::factory()->create(['website' => 'https://smile.ae', 'category' => BusinessCategory::Dentist]);
        $audit = WebsiteAudit::factory()->create(['business_id' => $business, 'score' => 85]);

        $result = $this->scoring()->score($business, $audit, [$this->opportunity(OpportunityType::OnlineBooking, OpportunityPriority::High)]);

        $this->assertFalse($result->has('strong_digital_presence'));
        $this->assertSame(15, $result->score);
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

        $this->assertSame([15, 15, 10, 0], array_column(array_values(array_filter(
            $result->breakdown,
            fn (array $item): bool => str_starts_with($item['key'], 'opportunity_'),
        )), 'points'));
        $this->assertSame(65, $result->score, 'Weak website 25 + capped opportunities 40.');
    }

    public function test_a_broken_website_is_not_counted_twice(): void
    {
        $business = Business::factory()->create(['website' => 'https://down.sa', 'category' => BusinessCategory::Dentist]);
        $audit = WebsiteAudit::factory()->failed(AuditFailure::ConnectionFailed)->create(['business_id' => $business]);

        $result = $this->scoring()->score($business, $audit, [$this->opportunity(OpportunityType::WebsiteRedesign, OpportunityPriority::High)]);

        $this->assertSame(['website_broken'], array_column($result->breakdown, 'key'));
        $this->assertSame(40, $result->score);
    }

    private function scoring(): OpportunityScoringService
    {
        return $this->app->make(OpportunityScoringService::class);
    }

    private function opportunity(OpportunityType $type, OpportunityPriority $priority): DetectedOpportunity
    {
        return new DetectedOpportunity($type, $priority, ['reason'], 'rule');
    }
}
