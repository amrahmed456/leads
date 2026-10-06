<?php

namespace Tests\Feature\Businesses;

use App\Enums\EntityType;
use App\Enums\EvidenceFact;
use App\Enums\LeadStatus;
use App\Models\Brand;
use App\Models\Business;
use App\Models\BusinessEvidence;
use App\Models\BusinessSource;
use App\Models\LeadScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessIntelligencePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_details_explain_the_identity_evidence_and_qualification(): void
    {
        $brand = Brand::factory()->international('Q524757')->create(['name' => 'KFC']);
        $business = Business::factory()->create([
            'name' => 'KFC King Fahd Road', 'brand_id' => $brand->id, 'brand_confidence' => 95, 'brand_source' => 'overture',
            'entity_type' => EntityType::InternationalChain, 'entity_confidence' => 95, 'independent_source_count' => 2,
            'entity_reasons' => [['code' => 'INTERNATIONAL_CHAIN', 'label' => 'KFC operates in several countries'], ['code' => 'POSSIBLE_BRAND_MATCH', 'label' => 'Hint', 'weak' => true]],
            'identity_conflicts' => [['fact' => 'website', 'severity' => 'minor', 'values' => [['value' => 'kfc.com.sa', 'provider' => 'openstreetmap', 'group' => 'osm_family']], 'preferred' => 'kfc.com.sa']],
        ]);
        BusinessSource::factory()->for($business)->create(['independence_group' => 'osm_family', 'match_reasons' => ['Same name (100% similar) 3 m apart']]);
        BusinessEvidence::factory()->for($business)->fact(EvidenceFact::Brand, 'KFC')->from('overture', 'overture')->create();
        LeadScore::factory()->for($business)->status(LeadStatus::Disqualified)->create([
            'eligibility_reasons' => [['code' => 'INTERNATIONAL_CHAIN', 'label' => 'KFC operates in several countries']],
            'fit_breakdown' => [['key' => 'entity_international_chain', 'label' => 'International chain: decisions are made centrally or elsewhere', 'points' => -100]],
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('businesses.show', $business))
            ->assertOk()
            ->assertSeeInOrder(['Lead qualification', 'Disqualified', 'Why?', 'INTERNATIONAL_CHAIN', 'KFC operates in several countries'])
            ->assertSee(['Business fit', 'Opportunity', 'Data confidence', 'Identity', 'International chain', 'Q524757', 'Evidence chain', 'osm_family', 'Same name (100% similar) 3 m apart', '(weak hint)', 'Minor: website']);
    }

    public function test_the_dashboard_shows_qualification_entity_and_source_analytics(): void
    {
        $chain = Business::factory()->create(['entity_type' => EntityType::NationalChain]);
        LeadScore::factory()->for($chain)->status(LeadStatus::Disqualified)->create();
        $local = Business::factory()->create(['entity_type' => EntityType::Independent]);
        LeadScore::factory()->for($local)->status(LeadStatus::Hot)->create();
        BusinessSource::factory()->for($local)->create(['provider' => 'overture', 'independence_group' => 'overture']);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Hot leads', '1', 'Warm leads', 'Review required', 'Low', 'Disqualified', '1', 'Chains &amp; enterprises rejected', '1'], escape: false)
            ->assertSee(['Entity types', 'National chain', 'Independent', 'Source contribution', 'Overture Places', 'osm_family']);
    }
}
