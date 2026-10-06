<?php

namespace Tests\Feature\Identity;

use App\Enums\BusinessCategory;
use App\Enums\EntityType;
use App\Enums\EvidenceFact;
use App\Enums\ReasonCode;
use App\Enums\SourceRole;
use App\Identity\BusinessIdentityAnalyzer;
use App\Identity\IdentityResult;
use App\Models\AtpSpider;
use App\Models\Brand;
use App\Models\Business;
use App\Models\BusinessEvidence;
use App\Models\BusinessSource;
use App\Models\SourcePlace;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_small_business_with_its_own_website_and_no_chain_evidence_is_independent(): void
    {
        $business = $this->business(['name' => 'Olaya Smiles', 'website' => 'https://olayasmiles.sa']);

        $result = $this->analyze($business);

        $this->assertSame(EntityType::Independent, $result->classification->type);
        $this->assertTrue($result->classification->has(ReasonCode::OwnWebsite));
        $this->assertSame([], $result->classification->weakSignals());
    }

    public function test_a_business_without_a_website_or_chain_evidence_is_a_local_business(): void
    {
        $result = $this->analyze($this->business(['name' => 'Abu Saad Tailoring', 'category' => BusinessCategory::Fashion]));

        $this->assertSame(EntityType::LocalBusiness, $result->classification->type);
        $this->assertTrue($result->classification->has(ReasonCode::SingleLocation));
    }

    public function test_a_government_website_domain_classifies_a_government_entity(): void
    {
        $result = $this->analyze($this->business(['name' => 'Riyadh Health Cluster Clinic', 'website' => 'https://www.moh.gov.sa/riyadh']));

        $this->assertSame(EntityType::Government, $result->classification->type);
        $this->assertSame(95, $result->classification->confidence);
    }

    public function test_an_openstreetmap_government_tag_classifies_a_government_entity(): void
    {
        $business = $this->business(['name' => 'Passports Office']);
        BusinessSource::factory()->for($business)->create(['raw_data' => ['tags' => ['office' => 'government', 'name' => 'Passports Office']]]);

        $this->assertSame(EntityType::Government, $this->analyze($business)->classification->type);
    }

    public function test_a_marketplace_is_an_aggregator_but_a_business_listed_on_one_is_not(): void
    {
        $talabat = $this->analyze($this->business(['name' => 'Talabat', 'website' => 'https://www.talabat.com']));
        $listed = $this->analyze($this->business(['name' => 'Burger Spot', 'website' => 'https://www.talabat.com/ksa/burger-spot', 'latitude' => 24.8]));

        $this->assertSame(EntityType::Aggregator, $talabat->classification->type);
        $this->assertSame(EntityType::LocalBusiness, $listed->classification->type, 'A marketplace page is not its own website.');
    }

    public function test_a_bank_brand_from_the_registry_is_an_enterprise(): void
    {
        $bank = Brand::factory()->national()->create(['name' => 'Al Rajhi Bank', 'entity_type' => EntityType::Enterprise, 'wikidata_id' => 'Q2638225']);
        $business = $this->business(['name' => 'Al Rajhi Bank Olaya']);
        $this->evidence($business, EvidenceFact::BrandWikidata, 'Q2638225');

        $result = $this->analyze($business);

        $this->assertSame(EntityType::Enterprise, $result->classification->type);
        $this->assertTrue($business->refresh()->brand->is($bank));
    }

    public function test_a_first_party_store_locator_branch_is_strong_chain_evidence(): void
    {
        $spider = AtpSpider::factory()->create(['spider' => 'albaik', 'brand_name' => 'Al Baik', 'brand_wikidata' => 'Q2649768']);
        SourcePlace::factory()->storeLocator($spider)->count(20)->sequence(fn ($sequence) => [
            'name' => 'Al Baik', 'city' => ['Jeddah', 'Mecca', 'Riyadh', 'Medina'][$sequence->index % 4], 'latitude' => 21.0 + $sequence->index / 10,
        ])->create();
        SourcePlace::factory()->storeLocator($spider)->create(['name' => 'Al Baik Olaya', 'latitude' => 24.70003, 'longitude' => 46.70003]);
        $business = $this->business(['name' => 'Al Baik']);

        $result = $this->analyze($business);

        $this->assertSame(EntityType::NationalChain, $result->classification->type);
        $this->assertTrue($result->classification->has(ReasonCode::FirstPartyChainSource));
        $this->assertTrue($business->sources()->where('provider', 'alltheplaces')->exists(), 'The store locator branch was linked.');
    }

    public function test_an_exact_registered_brand_name_with_a_large_verified_network_is_confident_enough_to_disqualify(): void
    {
        Brand::factory()->international('Q38076')->create(['name' => "McDonald's", 'location_count' => 24726]);

        $result = $this->analyze($this->business(['name' => "McDonald's"]));

        $this->assertSame(EntityType::InternationalChain, $result->classification->type);
        $this->assertGreaterThanOrEqual(70, $result->classification->confidence);
        $this->assertTrue($result->classification->has(ReasonCode::MultipleBranches));
    }

    public function test_an_exact_registered_brand_name_of_unknown_size_is_not_confident_enough_to_disqualify(): void
    {
        Brand::factory()->international()->create(['name' => 'Olaya Bites']);

        $result = $this->analyze($this->business(['name' => 'Olaya Bites']));

        $this->assertSame(EntityType::InternationalChain, $result->classification->type);
        $this->assertLessThan(70, $result->classification->confidence);
    }

    public function test_a_brand_operated_by_a_different_company_is_a_franchise(): void
    {
        Brand::factory()->create(['name' => 'Shawarma House', 'source' => 'openstreetmap']);
        $business = $this->business(['name' => 'Shawarma House Olaya']);
        $this->evidence($business, EvidenceFact::Brand, 'Shawarma House');
        $this->evidence($business, EvidenceFact::Operator, 'Al Faisal Trading');

        $result = $this->analyze($business);

        $this->assertSame(EntityType::Franchise, $result->classification->type);
        $this->assertTrue($result->classification->has(ReasonCode::Franchise));
    }

    public function test_several_locations_sharing_one_website_are_a_local_multi_branch_business(): void
    {
        foreach ([24.60, 24.65, 24.75] as $latitude) {
            $branch = $this->business(['name' => 'Smile Clinics', 'website' => 'https://smileclinics.sa', 'latitude' => $latitude]);
            $this->evidence($branch, EvidenceFact::Website, 'https://smileclinics.sa');
        }

        $result = $this->analyze(Business::first());

        $this->assertSame(EntityType::LocalMultiBranch, $result->classification->type);
        $this->assertTrue($result->classification->has(ReasonCode::MultipleBranches));
    }

    public function test_a_declared_parent_organization_is_corporate_but_needs_review_without_corroboration(): void
    {
        $business = $this->business(['name' => 'ABC Dental', 'website' => 'https://abcdental.sa']);
        WebsiteAudit::factory()->create([
            'business_id' => $business, 'url' => 'https://abcdental.sa', 'normalized_url' => 'https://abcdental.sa', 'title' => 'ABC Dental',
            'structured_data' => ['entities' => [['types' => ['Dentist'], 'name' => 'ABC Dental', 'parent_organization' => 'ABC Healthcare Group']]],
        ]);

        $result = $this->analyze($business, latestAudit: true);

        $this->assertSame(EntityType::Corporate, $result->classification->type);
        $this->assertSame(65, $result->classification->confidence);
        $this->assertTrue($result->classification->has(ReasonCode::ParentOrganization));
        $this->assertSame('ABC Healthcare Group', $business->relationships()->with('object')->sole()->object->name);
    }

    public function test_a_name_that_merely_contains_a_brand_name_is_only_a_weak_hint(): void
    {
        Brand::factory()->national()->create(['name' => 'Hamburgini']);

        $result = $this->analyze($this->business(['name' => 'Hamburgini Lovers Cafe']));

        $this->assertSame(EntityType::LocalBusiness, $result->classification->type, 'Never a chain on a resembling name alone.');
        $this->assertSame(ReasonCode::PossibleBrandMatch->value, $result->classification->weakSignals()[0]['code']);
        $this->assertNull($result->brand->brand);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function business(array $attributes): Business
    {
        return Business::factory()->create([
            'category' => BusinessCategory::Restaurant, 'latitude' => 24.70, 'longitude' => 46.70, 'website' => null, ...$attributes,
        ]);
    }

    private function evidence(Business $business, EvidenceFact $fact, string $value): void
    {
        BusinessEvidence::factory()->for($business)->fact($fact, $value)->from('openstreetmap', 'osm_family', SourceRole::Discovery)->create();
    }

    private function analyze(Business $business, bool $latestAudit = false): IdentityResult
    {
        $audit = $latestAudit ? $business->websiteAudits()->latest('id')->first() : null;

        return $this->app->make(BusinessIdentityAnalyzer::class)->analyze($business->fresh(), $audit);
    }
}
