<?php

namespace Tests\Feature\Identity;

use App\Enums\BusinessCategory;
use App\Enums\EntityType;
use App\Enums\EvidenceFact;
use App\Enums\EvidenceStatus;
use App\Intelligence\BusinessEvaluator;
use App\Jobs\AuditBusinessWebsite;
use App\Models\Business;
use App\Models\BusinessEvidence;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_audited_website_becomes_first_party_identity_evidence(): void
    {
        Http::fake(['https://smiledental.sa*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/websites/chain-branch.html')), 200, ['Content-Type' => 'text/html; charset=utf-8'])]);
        $business = Business::factory()->create([
            'name' => 'Smile Dental Olaya', 'website' => 'https://smiledental.sa', 'category' => BusinessCategory::Dentist,
            'phone' => '+966112003000', 'latitude' => 24.6905, 'longitude' => 46.6801,
        ]);
        $audit = $business->websiteAudits()->create(['url' => 'https://smiledental.sa', 'normalized_url' => 'https://smiledental.sa', 'queued_at' => now()]);

        AuditBusinessWebsite::dispatchSync($audit);

        $audit->refresh();
        $this->assertSame('Smile Healthcare Holding', $audit->structured_data['entities'][0]['parent_organization']);
        $this->assertSame(['https://smiledental.sa/franchise'], $audit->corporate_signals['franchise']);

        $evidence = BusinessEvidence::where('provider', 'website')->get();
        $this->assertSame(['first_party_web'], $evidence->pluck('independence_group')->unique()->values()->all());
        $this->assertSame('Smile Healthcare Holding', $evidence->firstWhere('fact_type', EvidenceFact::ParentOrganization)->value);
        $this->assertSame('Smile Dental Group', $evidence->firstWhere('fact_type', EvidenceFact::BranchOf)->value);
        $this->assertSame('The website names the business (Smile Dental Olaya)', $evidence->firstWhere('fact_type', EvidenceFact::Existence)->metadata['confirmed_by']);
        $this->assertEqualsCanonicalizing(['locations', 'franchise', 'careers'], $evidence->where('fact_type', EvidenceFact::CorporatePage)->pluck('value')->all());

        $business->refresh();
        $this->assertSame(EntityType::Corporate, $business->entity_type);
        $this->assertSame(1, $business->independent_source_count, 'Only the website confirms it in this test.');
    }

    public function test_a_website_that_does_not_name_the_business_does_not_confirm_it(): void
    {
        $business = Business::factory()->create(['name' => 'Olaya Grill', 'website' => 'https://parked.example', 'phone' => '+966500000001']);
        WebsiteAudit::factory()->create(['business_id' => $business, 'url' => 'https://parked.example', 'normalized_url' => 'https://parked.example', 'title' => 'Domain for sale']);

        $this->app->make(BusinessEvaluator::class)->evaluate($business);

        $this->assertFalse(BusinessEvidence::where('fact_type', EvidenceFact::Existence)->where('provider', 'website')->exists());
    }

    public function test_evidence_from_a_previous_website_becomes_stale(): void
    {
        $business = Business::factory()->create(['name' => 'Olaya Grill', 'website' => 'https://old-grill.sa']);
        WebsiteAudit::factory()->create(['business_id' => $business, 'url' => 'https://old-grill.sa', 'normalized_url' => 'https://old-grill.sa', 'title' => 'Olaya Grill']);
        $evaluator = $this->app->make(BusinessEvaluator::class);
        $evaluator->evaluate($business);

        $business->forceFill(['website' => 'https://olayagrill.sa'])->save();
        WebsiteAudit::factory()->create(['business_id' => $business, 'url' => 'https://olayagrill.sa', 'normalized_url' => 'https://olayagrill.sa', 'title' => 'Olaya Grill']);
        $evaluator->evaluate($business->refresh());

        $this->assertSame(EvidenceStatus::Stale, BusinessEvidence::where('source_record_id', 'https://old-grill.sa')->firstWhere('fact_type', EvidenceFact::Existence)->status);
        $this->assertSame(EvidenceStatus::Active, BusinessEvidence::where('source_record_id', 'https://olayagrill.sa')->firstWhere('fact_type', EvidenceFact::Existence)->status);
    }
}
