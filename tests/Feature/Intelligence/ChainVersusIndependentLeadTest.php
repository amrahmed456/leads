<?php

namespace Tests\Feature\Intelligence;

use App\Discovery\BusinessNormalizer;
use App\Discovery\BusinessRecorder;
use App\Discovery\Data\DiscoveredBusiness;
use App\Enums\BusinessCategory;
use App\Enums\EntityType;
use App\Enums\LeadStatus;
use App\Enums\ReasonCode;
use App\Enums\WebsiteSignal;
use App\Identity\Brands\NsiBrandImporter;
use App\Intelligence\BusinessEvaluator;
use App\Models\Business;
use App\Models\LeadScore;
use App\Models\SourcePlace;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The problem this upgrade solves: a real business is not automatically a
 * good prospect. A famous chain's branch must never become HOT, however
 * weak its local data looks; a small independent business with real
 * digital gaps should.
 */
class ChainVersusIndependentLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_kfc_branch_confirmed_by_several_sources_is_an_international_chain_and_disqualified(): void
    {
        // Brand registry: KFC is a worldwide brand in the Name Suggestion Index.
        $this->app->make(NsiBrandImporter::class)->importFile(base_path('tests/Fixtures/nsi-fast_food.json'), 'brands/amenity/fast_food');

        // Discovered through OpenStreetMap, tagged with its brand.
        $kfc = $this->discover(new DiscoveredBusiness(
            provider: 'openstreetmap', providerId: 'node/501', name: 'KFC', category: BusinessCategory::Restaurant, type: 'fast_food',
            countryCode: 'SA', latitude: 24.7136, longitude: 46.6753, phone: '920000000', website: 'https://www.kfc.com.sa',
            brand: 'KFC', brandWikidata: 'Q524757',
        ));

        // Overture lists the same branch with the brand and its Wikidata ID.
        SourcePlace::factory()->overture()->create([
            'name' => 'KFC - King Fahd Road', 'brand_name' => 'KFC', 'brand_wikidata' => 'Q524757',
            'latitude' => 24.71365, 'longitude' => 46.67535, 'phone' => '920000000', 'confidence' => 0.95,
            'provenance' => [['dataset' => 'meta'], ['dataset' => 'Microsoft']],
        ]);

        // Many more KFC branches across Saudi cities.
        SourcePlace::factory()->overture()->count(12)->sequence(fn ($sequence) => [
            'name' => 'KFC', 'brand_name' => 'KFC', 'brand_wikidata' => 'Q524757',
            'city' => ['Jeddah', 'Dammam', 'Mecca'][$sequence->index % 3],
            'latitude' => 21.4 + $sequence->index, 'longitude' => 39.2,
        ])->create();

        // Its website states the brand and the operating group.
        WebsiteAudit::factory()->create([
            'business_id' => $kfc, 'url' => 'https://www.kfc.com.sa', 'normalized_url' => 'https://www.kfc.com.sa',
            'title' => 'KFC Saudi Arabia', 'score' => 35, 'has_viewport' => false, 'has_online_ordering' => false,
            'structured_data' => ['entities' => [[
                'types' => ['Restaurant'], 'name' => 'KFC', 'brand' => 'KFC', 'parent_organization' => 'Americana Restaurants',
            ]]],
            'corporate_signals' => ['locations' => ['https://www.kfc.com.sa/locations'], 'careers' => ['https://www.kfc.com.sa/careers']],
        ]);

        $score = $this->evaluate($kfc);
        $kfc->refresh();

        $this->assertSame(EntityType::InternationalChain, $kfc->entity_type);
        $this->assertGreaterThanOrEqual(90, $kfc->entity_confidence);
        $this->assertSame('KFC', $kfc->name, 'The business keeps its own name.');
        $this->assertSame('KFC', $kfc->brand->name);
        $this->assertSame('Q524757', $kfc->brand->wikidata_id);
        $this->assertContains(ReasonCode::KnownBrand->value, $kfc->entity_reason_codes);
        $this->assertContains(ReasonCode::InternationalChain->value, $kfc->entity_reason_codes);
        $this->assertContains(ReasonCode::MultipleBranches->value, $kfc->entity_reason_codes);

        $this->assertSame(LeadStatus::Disqualified, $score->status);
        $this->assertSame(0, $score->score);
        $this->assertContains(ReasonCode::InternationalChain->value, array_column($score->eligibility_reasons, 'code'));
        $this->assertNotSame(LeadStatus::Hot, $score->status, 'A KFC branch must never be HOT.');
    }

    public function test_a_kfc_branch_with_only_a_matching_name_goes_to_review_instead_of_being_hot(): void
    {
        $this->app->make(NsiBrandImporter::class)->importFile(base_path('tests/Fixtures/nsi-fast_food.json'), 'brands/amenity/fast_food');

        // No brand tag, no website: only the name equals a registered brand.
        $kfc = $this->discover(new DiscoveredBusiness(
            provider: 'openstreetmap', providerId: 'node/777', name: 'KFC', category: BusinessCategory::Restaurant,
            type: 'fast_food', countryCode: 'SA', latitude: 24.7, longitude: 46.7, phone: '+966501112222',
        ));

        $score = $this->evaluate($kfc);

        $this->assertSame(EntityType::InternationalChain, $kfc->refresh()->entity_type);
        $this->assertLessThan(70, $kfc->entity_confidence, 'A name alone is not strong enough to disqualify automatically.');
        $this->assertSame(LeadStatus::ReviewRequired, $score->status);
    }

    public function test_an_independent_restaurant_with_a_weak_website_is_a_hot_lead(): void
    {
        $this->app->make(NsiBrandImporter::class)->importFile(base_path('tests/Fixtures/nsi-fast_food.json'), 'brands/amenity/fast_food');

        $reef = $this->discover(new DiscoveredBusiness(
            provider: 'openstreetmap', providerId: 'node/900', name: 'Al Reef Grill', category: BusinessCategory::Restaurant,
            type: 'restaurant', countryCode: 'SA', latitude: 24.69, longitude: 46.68, phone: '+966112345678',
            website: 'https://alreefgrill.sa',
        ));

        WebsiteAudit::factory()->create([
            'business_id' => $reef, 'url' => 'https://alreefgrill.sa', 'normalized_url' => 'https://alreefgrill.sa',
            'title' => 'Al Reef Grill | Home', 'score' => 30, 'has_viewport' => false, 'has_phone' => true,
            'has_online_ordering' => false, 'has_booking' => false, 'has_whatsapp' => false,
            'signals' => [WebsiteSignal::Phone->value => ['confidence' => 'high', 'evidence' => ['tel:'], 'data' => ['numbers' => ['+966112345678']]]],
            'structured_data' => ['entities' => [['types' => ['Restaurant'], 'name' => 'Al Reef Grill', 'telephone' => '+966112345678']]],
        ]);

        $score = $this->evaluate($reef);
        $reef->refresh();

        $this->assertSame(EntityType::Independent, $reef->entity_type);
        $this->assertNull($reef->brand_id);
        $this->assertSame(2, $reef->independent_source_count, 'OpenStreetMap and its own website.');
        $this->assertSame(LeadStatus::Hot, $score->status, json_encode($score->eligibility_reasons));
        $this->assertGreaterThanOrEqual(60, $score->data_confidence_score);
        $this->assertGreaterThanOrEqual(60, $score->business_fit_score);
        $this->assertGreaterThanOrEqual(45, $score->opportunity_score);
        $this->assertSame(ReasonCode::MeetsHotCriteria->value, $score->eligibility_reasons[0]['code']);
    }

    public function test_more_providers_from_the_same_dataset_do_not_raise_confidence(): void
    {
        $osm = new DiscoveredBusiness(
            provider: 'openstreetmap', providerId: 'node/42', name: 'Smile Dental', category: BusinessCategory::Dentist,
            countryCode: 'SA', latitude: 24.7, longitude: 46.7, phone: '+966501234567',
        );
        $business = $this->discover($osm);

        // Geoapify serves the same OpenStreetMap record.
        $this->discover(new DiscoveredBusiness(
            provider: 'geoapify', providerId: 'geo-1', name: 'Smile Dental', category: BusinessCategory::Dentist,
            countryCode: 'SA', latitude: 24.7, longitude: 46.7, phone: '+966501234567', identifiers: ['openstreetmap' => 'node/42'],
        ));

        $score = $this->evaluate($business);

        $this->assertSame(2, $business->sources()->count());
        $this->assertSame(1, $business->refresh()->independent_source_count, 'OpenStreetMap and Geoapify are one independence group.');
        $this->assertNotSame(LeadStatus::Hot, $score->status);
    }

    private function discover(DiscoveredBusiness $data): Business
    {
        $normalized = $this->app->make(BusinessNormalizer::class)->normalize($data);

        return $this->app->make(BusinessRecorder::class)->record($normalized, 'Riyadh')->business;
    }

    private function evaluate(Business $business): LeadScore
    {
        return $this->app->make(BusinessEvaluator::class)->evaluate($business->fresh());
    }
}
