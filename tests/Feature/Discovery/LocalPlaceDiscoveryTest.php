<?php

namespace Tests\Feature\Discovery;

use App\Discovery\CampaignDiscoveryService;
use App\Discovery\Data\DiscoveryQuery;
use App\Discovery\Exceptions\SourceNotApplicableException;
use App\Discovery\Providers\Overture\OvertureProvider;
use App\Enums\BusinessCategory;
use App\Enums\CampaignStatus;
use App\Enums\Country;
use App\Enums\SourceRunStatus;
use App\Jobs\DiscoverCampaignFromProvider;
use App\Jobs\QueueCampaignAudits;
use App\Jobs\RunCampaignDiscovery;
use App\Models\BusinessSource;
use App\Models\SearchCampaign;
use App\Models\SourcePlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class LocalPlaceDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_overture_discovery_searches_imported_places_by_radius_category_and_keyword(): void
    {
        SourcePlace::factory()->overture()->create(['name' => 'Inside Grill', 'latitude' => 24.7140, 'longitude' => 46.6760]);
        SourcePlace::factory()->overture()->create(['name' => 'Far Grill', 'latitude' => 24.80, 'longitude' => 46.80]);
        SourcePlace::factory()->overture()->create(['name' => 'Closed Grill', 'latitude' => 24.7141, 'longitude' => 46.6761, 'operating_status' => 'permanently_closed']);
        SourcePlace::factory()->overture()->create(['name' => 'Olaya Pharmacy', 'mapped_category' => BusinessCategory::Pharmacy, 'latitude' => 24.7142, 'longitude' => 46.6762]);
        SourcePlace::factory()->overture()->create(['name' => 'مطعم بلا تصنيف', 'mapped_category' => null, 'latitude' => 24.7143, 'longitude' => 46.6763]);

        $result = $this->app->make(OvertureProvider::class)->discover(new DiscoveryQuery(
            Country::SaudiArabia, 'Riyadh', 24.7136, 46.6753, 2000, BusinessCategory::Restaurant, ['مطعم'],
        ));

        $this->assertEqualsCanonicalizing(['Inside Grill', 'مطعم بلا تصنيف'], array_map(fn ($business) => $business->name, $result->businesses));
        $this->assertSame('overture', $result->businesses[0]->provider);
        $this->assertSame(0.9, $result->businesses[0]->confidence);
    }

    public function test_an_area_without_an_imported_extract_is_not_applicable(): void
    {
        $this->expectException(SourceNotApplicableException::class);

        $this->app->make(OvertureProvider::class)->discover(new DiscoveryQuery(
            Country::UnitedArabEmirates, 'Dubai', 25.2, 55.27, 2000, BusinessCategory::Restaurant,
        ));
    }

    public function test_an_overture_place_built_from_store_locator_data_is_not_independent_of_it(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        SourcePlace::factory()->overture()->create([
            'name' => 'Al Baik', 'latitude' => 24.7140, 'longitude' => 46.6760,
            // Real Overture records also list Overture's own confidence calculation.
            'provenance' => [['dataset' => 'AllThePlaces', 'record_id' => 'albaik/77'], ['dataset' => 'Overture', 'property' => '/properties/confidence']],
        ]);
        SourcePlace::factory()->overture()->create([
            'name' => 'Olaya Grill', 'latitude' => 24.7141, 'longitude' => 46.6770,
            'provenance' => [['dataset' => 'meta'], ['dataset' => 'AllThePlaces']],
        ]);

        RunCampaignDiscovery::dispatch(SearchCampaign::factory()->create(['sources' => ['overture']]));

        $this->assertSame('first_party', BusinessSource::whereHas('business', fn ($query) => $query->where('name', 'Al Baik'))->sole()->independence_group);
        $this->assertSame('overture', BusinessSource::whereHas('business', fn ($query) => $query->where('name', 'Olaya Grill'))->sole()->independence_group, 'Mixed provenance stays Overture.');
    }

    public function test_a_source_job_that_crashes_fails_only_its_source_and_completes_the_campaign(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        SourcePlace::factory()->overture()->create(['latitude' => 24.7140, 'longitude' => 46.6760]);
        $campaign = SearchCampaign::factory()->create(['sources' => ['openstreetmap', 'overture']]);
        [$osm, $overture] = $this->app->make(CampaignDiscoveryService::class)->start($campaign);
        DiscoverCampaignFromProvider::dispatchSync($overture);

        (new DiscoverCampaignFromProvider($osm))->failed(new RuntimeException('Worker killed'));

        $this->assertSame(SourceRunStatus::Failed, $osm->refresh()->status);
        $this->assertSame('Worker killed', $osm->error_message);
        $this->assertSame(CampaignStatus::Completed, $campaign->refresh()->status);
        $this->assertSame(1, $campaign->results_count);
        Queue::assertPushed(QueueCampaignAudits::class, 1);
    }
}
