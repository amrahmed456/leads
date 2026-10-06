<?php

namespace Tests\Feature\Discovery;

use App\Discovery\Data\DiscoveryQuery;
use App\Discovery\Exceptions\DiscoveryException;
use App\Discovery\Providers\Geoapify\GeoapifyProvider;
use App\Enums\BusinessCategory;
use App\Enums\Country;
use App\Enums\SourceRunStatus;
use App\Jobs\QueueCampaignAudits;
use App\Jobs\RunCampaignDiscovery;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Models\SearchCampaign;
use App\Sources\QuotaManager;
use App\Sources\SourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeoapifyProviderTest extends TestCase
{
    use RefreshDatabase;

    private const PLACES = 'https://api.geoapify.com/v2/places*';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(SourceRegistry::class)->saveCredentials('geoapify', ['api_key' => 'geo-secret-key-1234']);
    }

    public function test_it_normalizes_places_and_keeps_their_openstreetmap_id(): void
    {
        Http::fake([self::PLACES => Http::response($this->fixture('geoapify-riyadh-restaurants.json'))]);

        $result = $this->provider()->discover($this->restaurantsNearOlaya());

        $this->assertCount(2, $result->businesses);
        $this->assertSame(1, $result->skipped, 'A place without a name.');

        [$reef, $hamburgini] = $result->businesses;
        $this->assertSame('51geoapify-reef', $reef->providerId);
        $this->assertSame(['openstreetmap' => 'node/1001'], $reef->identifiers);
        $this->assertSame('Al Reef Restaurant', $reef->alternateName);
        $this->assertSame(BusinessCategory::Restaurant, $reef->category);
        $this->assertSame('SA', $reef->countryCode);
        $this->assertSame(['openstreetmap' => 'way/4242'], $hamburgini->identifiers);
        $this->assertSame('Q115555555', $hamburgini->brandWikidata);
        $this->assertSame('Hamburgini Co', $hamburgini->operator);

        Http::assertSent(fn (Request $request) => $request['categories'] === 'catering.restaurant,catering.fast_food,catering.food_court'
            && $request['filter'] === 'circle:46.675300,24.713600,2000'
            && $request['apiKey'] === 'geo-secret-key-1234');
    }

    public function test_credits_are_charged_by_places_returned(): void
    {
        Http::fake([self::PLACES => Http::response($this->fixture('geoapify-riyadh-restaurants.json'))]);

        $this->provider()->discover($this->restaurantsNearOlaya());

        $usage = $this->app->make(QuotaManager::class)->usage('geoapify');
        $this->assertSame(1, $usage->requests);
        $this->assertSame(1, $usage->units, 'Three places cost one credit (one per 20).');
    }

    public function test_a_used_up_daily_budget_stops_the_source_without_calling_it(): void
    {
        Http::fake();
        $state = $this->app->make(SourceRegistry::class)->state('geoapify');
        $state->settings = ['daily_quota' => 3];
        $state->save();
        $this->app->make(QuotaManager::class)->consume('geoapify', 3);

        try {
            $this->provider()->discover($this->restaurantsNearOlaya());
            $this->fail('The quota should stop the request.');
        } catch (DiscoveryException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('quota of [geoapify] is used up', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_quota_response_pauses_the_source_for_the_day_without_retrying(): void
    {
        Http::fake([self::PLACES => Http::response(['message' => 'Too many requests'], 429)]);

        try {
            $this->provider()->discover($this->restaurantsNearOlaya());
            $this->fail('Expected a permanent failure.');
        } catch (DiscoveryException $e) {
            $this->assertFalse($e->retryable);
        }

        $this->assertTrue($this->app->make(QuotaManager::class)->isExhausted('geoapify'));

        $this->expectException(DiscoveryException::class);
        $this->provider()->discover($this->restaurantsNearOlaya());
    }

    public function test_the_api_key_never_appears_in_a_stored_error(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        Http::fake([self::PLACES => Http::response('Invalid request for apiKey=geo-secret-key-1234', 400)]);
        $campaign = SearchCampaign::factory()->create(['sources' => ['geoapify']]);

        RunCampaignDiscovery::dispatch($campaign);

        $run = $campaign->sourceRuns()->sole();
        $this->assertSame(SourceRunStatus::Failed, $run->status);
        $this->assertStringNotContainsString('geo-secret-key-1234', $run->error_message);
        $this->assertStringNotContainsString('geo-secret-key-1234', (string) $campaign->refresh()->error_message);
        $this->assertStringNotContainsString('geo-secret-key-1234', (string) $this->app->make(SourceRegistry::class)->state('geoapify')->last_error);
    }

    public function test_a_place_found_by_openstreetmap_and_geoapify_is_one_business_with_one_independent_group(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        Http::fake([
            'https://overpass.test/*' => Http::response($this->fixture('overpass-riyadh-restaurants.json')),
            self::PLACES => Http::response($this->fixture('geoapify-riyadh-restaurants.json')),
        ]);
        $campaign = SearchCampaign::factory()->create(['sources' => ['openstreetmap', 'geoapify']]);

        RunCampaignDiscovery::dispatch($campaign);

        $reef = BusinessSource::where('provider', 'geoapify')->where('provider_id', '51geoapify-reef')->sole()->business;
        $this->assertSame('مطعم الريف', $reef->name);
        $this->assertSame(['geoapify', 'openstreetmap'], $reef->sources()->orderBy('provider')->pluck('provider')->unique()->values()->all());
        $this->assertSame(['osm_family'], $reef->sources()->pluck('independence_group')->unique()->values()->all());
        $this->assertSame(['Same openstreetmap record (node/1001)'], BusinessSource::where('provider', 'geoapify')->where('provider_id', '51geoapify-reef')->sole()->match_reasons);
        $this->assertSame(5, Business::count(), '4 from OpenStreetMap plus Hamburgini.');
    }

    private function provider(): GeoapifyProvider
    {
        return $this->app->make(GeoapifyProvider::class);
    }

    private function restaurantsNearOlaya(): DiscoveryQuery
    {
        return new DiscoveryQuery(Country::SaudiArabia, 'Riyadh', 24.7136, 46.6753, 2000, BusinessCategory::Restaurant);
    }
}
