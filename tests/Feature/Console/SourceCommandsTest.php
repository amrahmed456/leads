<?php

namespace Tests\Feature\Console;

use App\Enums\CampaignStatus;
use App\Enums\Country;
use App\Jobs\RecalculateLeadEligibility;
use App\Jobs\RunCampaignDiscovery;
use App\Jobs\SyncAllThePlacesSpider;
use App\Models\AtpSpider;
use App\Models\Brand;
use App\Models\Business;
use App\Models\GeoPlace;
use App\Models\LeadScore;
use App\Models\SearchCampaign;
use App\Models\SourcePlace;
use App\Sources\GeoNames\CityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SourceCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_providers_health_lists_every_source(): void
    {
        $this->artisan('providers:health')
            ->expectsOutputToContain('OpenStreetMap / Overpass')
            ->expectsOutputToContain('Needs credentials')
            ->assertSuccessful();
    }

    public function test_recalculating_eligibility_in_process_scores_every_business(): void
    {
        Business::factory()->count(2)->create(['website' => null, 'phone' => '+966501234567']);

        $this->artisan('businesses:recalculate-eligibility', ['--sync' => true])
            ->expectsOutputToContain('Re-evaluated 2 business(es)')
            ->assertSuccessful();

        $this->assertSame(2, LeadScore::where('version', '2')->count());
    }

    public function test_recalculating_outdated_scores_only_queues_those_scored_with_older_rules(): void
    {
        Queue::fake([RecalculateLeadEligibility::class]);
        $current = Business::factory()->create();
        LeadScore::factory()->for($current)->create(['version' => '2']);
        $outdated = Business::factory()->create();
        LeadScore::factory()->for($outdated)->create(['version' => '1']);
        $unscored = Business::factory()->create();

        $this->artisan('businesses:recalculate-eligibility', ['--outdated' => true])->assertSuccessful();

        Queue::assertPushed(RecalculateLeadEligibility::class, fn (RecalculateLeadEligibility $job) => $job->businessIds === [$outdated->id, $unscored->id]);
    }

    public function test_the_brand_registry_can_be_built_from_a_local_file(): void
    {
        $this->artisan('brands:refresh', ['--category' => ['brands/amenity/fast_food'], '--file' => base_path('tests/Fixtures/nsi-fast_food.json')])
            ->expectsOutputToContain('Imported 3 brands')
            ->assertSuccessful();

        $this->assertSame(3, Brand::count());
    }

    public function test_sources_sync_queues_only_enabled_spiders_not_synced_recently(): void
    {
        Queue::fake([SyncAllThePlacesSpider::class]);
        $due = AtpSpider::factory()->create(['last_success_at' => now()->subDays(10)]);
        AtpSpider::factory()->create(['last_success_at' => now()->subDay()]);
        AtpSpider::factory()->create(['enabled' => false]);

        $this->artisan('sources:sync')->expectsOutputToContain('Queued 1 spider sync(s).')->assertSuccessful();

        Queue::assertPushed(SyncAllThePlacesSpider::class, 1);
        Queue::assertPushed(SyncAllThePlacesSpider::class, fn (SyncAllThePlacesSpider $job) => $job->spider->is($due));
    }

    public function test_an_overture_extract_on_the_server_can_be_imported(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('imports/overture/riyadh.ndjson', (string) file_get_contents(base_path('tests/Fixtures/overture-riyadh.ndjson')));

        $this->artisan('overture:import', ['path' => 'imports/overture/riyadh.ndjson', '--country' => 'SA', '--sync' => true])
            ->assertSuccessful();

        $this->assertSame(3, SourcePlace::count());

        $this->artisan('overture:import', ['path' => '../../.env'])->assertFailed();
    }

    public function test_geonames_cities_are_imported_and_resolve_arabic_names(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('cities.txt', implode("\n", [
            "108410\tRiyadh\tRiyadh\tAr Riyad,Riyad,الرياض,Эр-Рияд\t24.68773\t46.72185\tP\tPPLC\tSA\t\t10\t\t\t\t4205961\t\t612\tAsia/Riyadh\t2024-01-01",
            "292223\tDubai\tDubai\tDubayy,دبي\t25.07725\t55.30927\tP\tPPLA\tAE\t\t03\t\t\t\t3790000\t\t5\tAsia/Dubai\t2024-01-01",
            "5128581\tNew York City\tNew York City\tNYC\t40.71427\t-74.00597\tP\tPPL\tUS\t\tNY\t\t\t\t8804190\t\t10\tAmerica/New_York\t2024-01-01",
        ]));

        $this->artisan('geo:import', ['type' => 'geonames', 'path' => Storage::disk('local')->path('cities.txt')])
            ->expectsOutputToContain('Imported 2 cities.')
            ->assertSuccessful();

        $riyadh = GeoPlace::where('geonames_id', 108410)->sole();
        $this->assertSame('الرياض', $riyadh->name_ar);
        $this->assertSame('Riyadh', $this->app->make(CityResolver::class)->canonicalName('الرياض', Country::SaudiArabia));
        $this->assertNull($this->app->make(CityResolver::class)->canonicalName('Atlantis', Country::SaudiArabia));
    }

    public function test_campaigns_are_located_with_geonames_before_nominatim(): void
    {
        GeoPlace::query()->create([
            'geonames_id' => 292223, 'name' => 'Dubai', 'ascii_name' => 'Dubai', 'name_ar' => 'دبي', 'search_names' => '|dubai|دبي|',
            'country_code' => 'AE', 'feature_code' => 'PPLA', 'latitude' => 25.07725, 'longitude' => 55.30927, 'population' => 3790000,
        ]);
        $campaign = SearchCampaign::factory()->withoutCoordinates()->create(['country_code' => 'AE', 'city' => 'دبي', 'sources' => ['overture']]);

        RunCampaignDiscovery::dispatchSync($campaign);

        $campaign->refresh();
        $this->assertSame(25.07725, $campaign->latitude);
        $this->assertSame('Dubai, United Arab Emirates (GeoNames)', $campaign->resolved_location);
        $this->assertSame(CampaignStatus::Failed, $campaign->status, 'No Overture extract covers Dubai, so the only source was skipped.');
        $this->assertStringContainsString('No imported Overture extract covers this area', $campaign->error_message);
    }

    public function test_reverify_queues_stale_completed_campaigns(): void
    {
        Queue::fake([RunCampaignDiscovery::class]);
        $stale = SearchCampaign::factory()->status(CampaignStatus::Completed)->create(['completed_at' => now()->subDays(40)]);
        SearchCampaign::factory()->status(CampaignStatus::Completed)->create(['completed_at' => now()->subDays(5)]);

        $this->artisan('campaigns:reverify')->expectsOutputToContain('Queued 1 campaign(s)')->assertSuccessful();

        Queue::assertPushed(RunCampaignDiscovery::class, fn (RunCampaignDiscovery $job) => $job->campaign->is($stale));
        $this->assertSame(CampaignStatus::Pending, $stale->refresh()->status);
    }
}
