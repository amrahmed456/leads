<?php

namespace Tests\Feature\Businesses;

use App\Enums\BusinessCategory;
use App\Enums\EntityType;
use App\Enums\LeadStatus;
use App\Enums\OpportunityType;
use App\Livewire\Businesses\Index;
use App\Models\Business;
use App\Models\LeadScore;
use App\Models\Opportunity;
use App\Models\SearchCampaign;
use App\Models\User;
use App\Models\WebsiteAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessListTest extends TestCase
{
    use RefreshDatabase;

    private Business $kudu;

    private Business $smile;

    private Business $reef;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kudu = Business::factory()->withSource('openstreetmap')->create([
            'name' => 'Kudu', 'city' => 'Riyadh', 'category' => BusinessCategory::Restaurant,
            'phone' => '+966112345678', 'website' => 'https://kudu.com.sa', 'rating' => 4.1, 'reviews_count' => 50,
        ]);
        $this->smile = Business::factory()->withSource('openstreetmap')->create([
            'name' => 'Smile Dental', 'city' => 'Jeddah', 'category' => BusinessCategory::Dentist,
            'phone' => null, 'website' => null, 'rating' => 4.8, 'reviews_count' => 10,
        ]);
        $this->reef = Business::factory()->withSource('google_places', 'ChIJ123')->create([
            'name' => 'مطعم الريف', 'alternate_name' => 'Al Reef', 'city' => 'Riyadh', 'category' => BusinessCategory::Restaurant,
            'phone' => '+966501234567', 'website' => null, 'rating' => null, 'reviews_count' => null,
        ]);
    }

    public function test_the_list_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('businesses.index'))
            ->assertOk()
            ->assertSee(['Kudu', 'Smile Dental', 'مطعم الريف']);
    }

    public function test_search_matches_names_alternate_names_and_local_phone_formats(): void
    {
        $this->assertListed(['Kudu'], $this->list()->set('search', 'kud'));
        $this->assertListed(['مطعم الريف'], $this->list()->set('search', 'al reef'));
        $this->assertListed(['مطعم الريف'], $this->list()->set('search', 'مطعم'));
        $this->assertListed(['مطعم الريف'], $this->list()->set('search', '050 123 4567'));
    }

    public function test_filters(): void
    {
        $campaign = SearchCampaign::factory()->create();
        $campaign->businesses()->attach($this->smile);

        $this->assertListed(['Smile Dental'], $this->list()->set('campaign', (string) $campaign->id));
        $this->assertListed(['مطعم الريف'], $this->list()->set('provider', 'google_places'));
        $this->assertListed(['Kudu', 'مطعم الريف'], $this->list()->set('city', 'Riyadh'));
        $this->assertListed(['Smile Dental'], $this->list()->set('category', 'dentist'));
        $this->assertListed(['Smile Dental', 'مطعم الريف'], $this->list()->set('website', 'without'));
        $this->assertListed(['Smile Dental'], $this->list()->set('phone', 'without'));
        $this->assertListed(['Kudu'], $this->list()->set('city', 'Riyadh')->set('website', 'with'));
    }

    public function test_sorting_puts_missing_ratings_and_review_counts_last(): void
    {
        $this->assertListed(['Kudu', 'Smile Dental', 'مطعم الريف'], $this->list()->set('sort', 'name'), ordered: true);
        $this->assertListed(['Smile Dental', 'Kudu', 'مطعم الريف'], $this->list()->set('sort', 'rating'), ordered: true);
        $this->assertListed(['Kudu', 'Smile Dental', 'مطعم الريف'], $this->list()->set('sort', 'reviews'), ordered: true);
    }

    public function test_unknown_filter_values_are_ignored(): void
    {
        $this->assertListed(['Kudu', 'Smile Dental', 'مطعم الريف'], $this->list()
            ->set('category', 'nope')
            ->set('campaign', '1 OR 1=1')
            ->set('sort', 'id; DROP TABLE businesses'));
    }

    public function test_sorting_by_lead_score_lists_unscored_businesses_last(): void
    {
        LeadScore::factory()->for($this->smile)->status(LeadStatus::Low)->create(['score' => 40]);
        LeadScore::factory()->for($this->reef)->status(LeadStatus::Hot)->create(['score' => 75]);

        $this->assertListed(['مطعم الريف', 'Smile Dental', 'Kudu'], $this->list()->set('sort', 'lead_score'), ordered: true);
        $this->assertListed(['مطعم الريف'], $this->list()->set('status', 'hot'));
    }

    public function test_disqualified_businesses_are_hidden_by_default(): void
    {
        LeadScore::factory()->for($this->kudu)->status(LeadStatus::Disqualified)->create();
        LeadScore::factory()->for($this->reef)->status(LeadStatus::Warm)->create();

        $this->assertListed(['Smile Dental', 'مطعم الريف'], $this->list());
        $this->assertListed(['Kudu', 'Smile Dental', 'مطعم الريف'], $this->list()->set('hideDisqualified', false));
        $this->assertListed(['Kudu'], $this->list()->set('status', 'disqualified'));
    }

    public function test_filters_by_entity_type_brand_and_independence(): void
    {
        $this->kudu->forceFill(['entity_type' => EntityType::NationalChain])->save();
        $this->reef->forceFill(['entity_type' => EntityType::Independent])->save();
        $this->smile->forceFill(['entity_type' => EntityType::LocalBusiness])->save();

        $this->assertListed(['Kudu'], $this->list()->set('entity', 'national_chain'));
        $this->assertListed(['Kudu'], $this->list()->set('entity', 'chain'));
        $this->assertListed(['Smile Dental', 'مطعم الريف'], $this->list()->set('entity', 'independent'));
    }

    public function test_filters_by_minimum_scores_and_independent_evidence(): void
    {
        LeadScore::factory()->for($this->kudu)->create(['data_confidence_score' => 70, 'business_fit_score' => 40, 'opportunity_score' => 60]);
        LeadScore::factory()->for($this->reef)->create(['data_confidence_score' => 30, 'business_fit_score' => 80, 'opportunity_score' => 20]);
        $this->reef->forceFill(['independent_source_count' => 2])->save();

        $this->assertListed(['Kudu'], $this->list()->set('minConfidence', '60'));
        $this->assertListed(['مطعم الريف'], $this->list()->set('minFit', '60'));
        $this->assertListed(['Kudu'], $this->list()->set('minOpportunity', '45'));
        $this->assertListed(['مطعم الريف'], $this->list()->set('minEvidence', '2'));
        $this->assertListed(['Kudu', 'مطعم الريف'], $this->list()->set('sort', 'confidence')->set('minConfidence', '25'), ordered: true);
    }

    public function test_filters_by_website_features_from_the_latest_audit(): void
    {
        WebsiteAudit::factory()->create(['business_id' => $this->kudu, 'has_whatsapp' => false, 'has_arabic' => true]);

        $this->assertListed(['Kudu'], $this->list()->set('feature', 'no_whatsapp'));
        $this->assertListed(['Kudu'], $this->list()->set('feature', 'arabic'));
        $this->assertListed([], $this->list()->set('feature', 'whatsapp'));
    }

    public function test_filters_by_opportunity(): void
    {
        Opportunity::factory()->for($this->reef)->create(['type' => OpportunityType::WebsiteMissing]);
        Opportunity::factory()->for($this->kudu)->create(['type' => OpportunityType::OnlineOrdering]);

        $this->assertListed(['مطعم الريف'], $this->list()->set('opportunity', 'website_missing'));
        $this->assertListed(['Kudu', 'Smile Dental', 'مطعم الريف'], $this->list()->set('opportunity', 'not-a-type'));
    }

    public function test_business_details_show_all_available_information(): void
    {
        $this->kudu->update(['email' => 'hello@kudu.com.sa', 'social_links' => ['instagram' => 'https://www.instagram.com/kudu']]);

        $this->actingAs(User::factory()->create())
            ->get(route('businesses.show', $this->kudu))
            ->assertOk()
            ->assertSee(['Kudu', '+966112345678', 'https://kudu.com.sa', 'hello@kudu.com.sa', 'https://www.instagram.com/kudu', 'OpenStreetMap']);
    }

    private function list(): Testable
    {
        return Livewire::actingAs(User::factory()->create())->test(Index::class);
    }

    /**
     * @param  list<string>  $names
     */
    private function assertListed(array $names, Testable $component, bool $ordered = false): void
    {
        $listed = $component->viewData('businesses')->getCollection()->pluck('name')->all();

        if (! $ordered) {
            sort($listed);
            sort($names);
        }

        $this->assertSame($names, $listed);
    }
}
