<?php

namespace Tests\Feature\Intelligence;

use App\Enums\BusinessCategory;
use App\Enums\OpportunityType;
use App\Intelligence\BusinessEvaluator;
use App\Models\Business;
use App\Models\LeadScore;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_the_opportunities_and_lead_score_of_a_business(): void
    {
        $business = Business::factory()->create(['website' => null, 'phone' => '+966501234567']);

        $score = $this->evaluator()->evaluate($business);

        $this->assertSame([OpportunityType::WebsiteMissing], $business->opportunities()->pluck('type')->all());
        $this->assertTrue($score->business->is($business));
        $this->assertSame(1, LeadScore::count());
    }

    public function test_re_evaluation_removes_solved_opportunities_and_keeps_the_first_detection_date(): void
    {
        $this->freezeTime();
        $business = Business::factory()->create(['website' => 'https://smile.ae', 'category' => BusinessCategory::Dentist]);
        WebsiteAudit::factory()->create(['business_id' => $business, 'url' => 'https://smile.ae', 'normalized_url' => 'https://smile.ae', 'has_phone' => true]);
        $this->evaluator()->evaluate($business);
        $firstDetection = $business->opportunities()->where('type', OpportunityType::WhatsappIntegration)->sole()->detected_at;

        $this->travel(2)->days();
        $newAudit = WebsiteAudit::factory()->create(['business_id' => $business, 'url' => 'https://smile.ae', 'normalized_url' => 'https://smile.ae', 'has_phone' => true, 'has_booking' => true]);
        $this->evaluator()->evaluate($business);

        $whatsapp = $business->opportunities()->sole();
        $this->assertSame(OpportunityType::WhatsappIntegration, $whatsapp->type, 'Online booking was added, so that opportunity is gone.');
        $this->assertTrue($whatsapp->detected_at->equalTo($firstDetection));
        $this->assertSame($newAudit->id, $whatsapp->website_audit_id);
        $this->assertSame(1, LeadScore::count());
    }

    public function test_an_audit_of_a_previous_website_is_ignored(): void
    {
        $business = Business::factory()->create(['website' => 'https://new.example.com', 'category' => BusinessCategory::Dentist]);
        WebsiteAudit::factory()->create(['business_id' => $business, 'url' => 'https://old.example.com', 'normalized_url' => 'https://old.example.com']);

        $score = $this->evaluator()->evaluate($business);

        $this->assertSame([], $business->opportunities()->pluck('type')->all());
        $this->assertSame('website_not_audited', $score->opportunity_breakdown[0]['key']);
    }

    private function evaluator(): BusinessEvaluator
    {
        return $this->app->make(BusinessEvaluator::class);
    }
}
