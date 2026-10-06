<?php

namespace Tests\Feature\Discovery;

use App\Discovery\CampaignDiscoveryService;
use App\Enums\CampaignStatus;
use App\Enums\SourceRunStatus;
use App\Jobs\DiscoverCampaignFromProvider;
use App\Jobs\QueueCampaignAudits;
use App\Jobs\RunCampaignDiscovery;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Models\CampaignSourceRun;
use App\Models\SearchCampaign;
use App\Models\SourcePlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignDiscoveryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const OVERPASS = 'https://overpass.test/api/interpreter';

    private const NOMINATIM = 'https://nominatim.test/search*';

    public function test_discovery_geocodes_the_city_and_stores_normalized_deduplicated_businesses(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        Http::fake([
            self::NOMINATIM => Http::response($this->fixture('nominatim-riyadh.json')),
            self::OVERPASS => Http::response($this->fixture('overpass-riyadh-restaurants.json')),
        ]);

        $campaign = SearchCampaign::factory()->withoutCoordinates()->create(['keywords' => ['مطعم', 'cafe']]);

        RunCampaignDiscovery::dispatch($campaign);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertNull($campaign->error_message);
        $this->assertNotNull($campaign->started_at);
        $this->assertNotNull($campaign->completed_at);

        // Location resolved through Nominatim and remembered on the campaign.
        $this->assertSame(24.6319692, $campaign->latitude);
        $this->assertSame(46.7150648, $campaign->longitude);
        $this->assertSame('Riyadh, Riyadh Region, Saudi Arabia', $campaign->resolved_location);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://nominatim.test/search')
            && $request['q'] === 'Riyadh' && $request['countrycodes'] === 'sa');
        Http::assertSent(fn (Request $request) => $request->url() === self::OVERPASS
            && str_contains($request['data'], '(around:2000,24.6319692,46.7150648)'));

        // 5 usable records: node/1001 and way/2002 are the same restaurant → 4 businesses.
        $this->assertSame(4, Business::count());
        $this->assertSame(5, BusinessSource::count());
        $this->assertSame(4, $campaign->results_count);
        $this->assertSame(4, $campaign->new_businesses_count);
        $this->assertSame(4, $campaign->skipped_count);
        $this->assertSame(4, $campaign->businesses()->count());

        $reef = Business::where('name', 'مطعم الريف')->with('sources')->sole();
        $this->assertSame('Al Reef Restaurant', $reef->alternate_name);
        $this->assertSame('+966112345678', $reef->phone);
        $this->assertSame('https://www.alreef.sa/?menu=1', $reef->website);
        $this->assertSame('info@alreef.sa', $reef->email);
        $this->assertSame('Riyadh', $reef->city);
        $this->assertSame('SA', $reef->country_code);
        $this->assertCount(2, $reef->sources);

        $burger = Business::where('name', 'Burger Spot')->sole();
        $this->assertNull($burger->website, 'An Instagram page is not a website.');
        $this->assertEquals([
            'instagram' => 'https://instagram.com/burgerspot.sa',
            'whatsapp' => 'https://wa.me/966555123456',
        ], $burger->social_links);
        $this->assertNull($burger->phone);

        $this->assertSame('+966112223333', Business::where('name', 'Olaya Food Court')->sole()->phone);

        Queue::assertPushed(QueueCampaignAudits::class, fn (QueueCampaignAudits $job) => $job->campaign->is($campaign));
    }

    public function test_an_overlapping_campaign_reuses_existing_businesses(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        Http::fake([self::OVERPASS => Http::response($this->fixture('overpass-riyadh-restaurants.json'))]);

        $first = SearchCampaign::factory()->create();
        RunCampaignDiscovery::dispatch($first);

        $second = SearchCampaign::factory()->create(['name' => 'Olaya restaurants', 'radius_meters' => 1000]);
        RunCampaignDiscovery::dispatch($second);

        $second->refresh();
        $this->assertSame(CampaignStatus::Completed, $second->status);
        $this->assertSame(4, Business::count(), 'No duplicates across overlapping campaigns.');
        $this->assertSame(5, BusinessSource::count());
        $this->assertSame(4, $second->results_count);
        $this->assertSame(0, $second->new_businesses_count);
        $this->assertSame(4, $second->businesses()->count());
        $this->assertSame(2, Business::first()->campaigns()->count());
    }

    public function test_running_a_campaign_again_does_not_duplicate_anything(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        Http::fake([self::OVERPASS => Http::response($this->fixture('overpass-riyadh-restaurants.json'))]);

        $campaign = SearchCampaign::factory()->create();
        RunCampaignDiscovery::dispatch($campaign);
        $campaign->markQueued();
        RunCampaignDiscovery::dispatch($campaign);

        $this->assertSame(4, Business::count());
        $this->assertSame(4, $campaign->businesses()->count());
        $this->assertSame(0, $campaign->refresh()->new_businesses_count);
    }

    public function test_a_failed_provider_request_fails_the_campaign_and_is_logged(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        Event::fake([MessageLogged::class]);
        Http::fake([self::OVERPASS => Http::response('Service Unavailable', 503)]);

        $campaign = SearchCampaign::factory()->create();

        RunCampaignDiscovery::dispatch($campaign);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Failed, $campaign->status);
        $this->assertStringContainsString('HTTP 503', $campaign->error_message);
        $this->assertSame(0, Business::count());

        Event::assertDispatched(MessageLogged::class, fn (MessageLogged $log) => $log->level === 'error'
            && $log->message === 'Campaign discovery failed.'
            && $log->context['campaign_id'] === $campaign->id
            && str_contains($log->context['message'], 'HTTP 503'));
        Queue::assertNotPushed(QueueCampaignAudits::class);
    }

    public function test_an_unknown_location_fails_the_campaign_without_querying_the_provider(): void
    {
        Http::fake([self::NOMINATIM => Http::response([])]);

        $campaign = SearchCampaign::factory()->withoutCoordinates()->create(['city' => 'Atlantis']);

        RunCampaignDiscovery::dispatch($campaign);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Failed, $campaign->status);
        $this->assertStringContainsString('Could not find "Atlantis" in Saudi Arabia', $campaign->error_message);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::OVERPASS);
    }

    public function test_a_transient_geocoding_failure_is_released_for_a_later_retry(): void
    {
        Http::fake([self::NOMINATIM => Http::response('Service Unavailable', 503)]);

        $campaign = SearchCampaign::factory()->withoutCoordinates()->create();
        $job = (new RunCampaignDiscovery($campaign))->withFakeQueueInteractions();

        $job->handle($this->app->make(CampaignDiscoveryService::class));

        $job->assertReleased(delay: 60);
        $this->assertSame(CampaignStatus::Pending, $campaign->refresh()->status);
        $this->assertStringStartsWith('Attempt 1 failed:', $campaign->error_message);
    }

    public function test_a_transient_source_failure_is_released_for_a_later_retry(): void
    {
        Http::fake([self::OVERPASS => Http::response('Too Many Requests', 429)]);

        $run = $this->startedRun(SearchCampaign::factory()->create());
        $job = (new DiscoverCampaignFromProvider($run))->withFakeQueueInteractions();

        $job->handle($this->app->make(CampaignDiscoveryService::class));

        $job->assertReleased(delay: 60);
        $this->assertSame(SourceRunStatus::Pending, $run->refresh()->status);
        $this->assertStringStartsWith('Attempt 1 failed:', $run->error_message);
        $this->assertSame(CampaignStatus::Running, $run->campaign->refresh()->status);
    }

    public function test_the_last_attempt_fails_the_source_and_the_campaign_when_no_source_succeeded(): void
    {
        Http::fake([self::OVERPASS => Http::response('Too Many Requests', 429)]);

        $run = $this->startedRun(SearchCampaign::factory()->create());
        $job = (new DiscoverCampaignFromProvider($run))->withFakeQueueInteractions();
        $job->job->attempts = 3;

        $job->handle($this->app->make(CampaignDiscoveryService::class));

        $job->assertNotReleased();
        $this->assertSame(SourceRunStatus::Failed, $run->refresh()->status);
        $this->assertSame(CampaignStatus::Failed, $run->campaign->refresh()->status);
        $this->assertStringContainsString('HTTP 429', $run->campaign->error_message);
    }

    public function test_a_permanent_source_failure_is_not_retried(): void
    {
        Http::fake([self::OVERPASS => Http::response('parse error', 400)]);

        $run = $this->startedRun(SearchCampaign::factory()->create());
        $job = (new DiscoverCampaignFromProvider($run))->withFakeQueueInteractions();

        $job->handle($this->app->make(CampaignDiscoveryService::class));

        $job->assertNotReleased();
        $this->assertSame(SourceRunStatus::Failed, $run->refresh()->status);
    }

    public function test_a_failing_source_never_discards_what_the_other_sources_found(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        Http::fake([self::OVERPASS => Http::response('Service Unavailable', 503)]);
        SourcePlace::factory()->overture()->create(['name' => 'Olaya Grill', 'latitude' => 24.7140, 'longitude' => 46.6760]);

        $campaign = SearchCampaign::factory()->create(['sources' => ['openstreetmap', 'overture']]);

        RunCampaignDiscovery::dispatch($campaign);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertSame(1, $campaign->results_count);
        $this->assertStringContainsString('OpenStreetMap / Overpass: The Overpass API is busy', $campaign->error_message);
        $this->assertSame(['openstreetmap' => SourceRunStatus::Failed, 'overture' => SourceRunStatus::Completed],
            $campaign->sourceRuns()->pluck('status', 'source')->all());
        $this->assertSame('Olaya Grill', Business::sole()->name);
        Queue::assertPushed(QueueCampaignAudits::class);
    }

    public function test_an_unavailable_source_is_skipped_with_the_reason(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        Http::fake([self::OVERPASS => Http::response($this->fixture('overpass-riyadh-restaurants.json'))]);

        $campaign = SearchCampaign::factory()->create(['sources' => ['openstreetmap', 'geoapify']]);

        RunCampaignDiscovery::dispatch($campaign);

        $geoapify = $campaign->sourceRuns()->where('source', 'geoapify')->sole();
        $this->assertSame(SourceRunStatus::Skipped, $geoapify->status);
        $this->assertSame('Geoapify Places needs credentials (Settings → Data Sources).', $geoapify->error_message);
        $this->assertSame(CampaignStatus::Completed, $campaign->refresh()->status);
        $this->assertSame(4, $campaign->results_count);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'geoapify'));
    }

    public function test_a_campaign_created_before_multi_source_discovery_uses_its_provider(): void
    {
        Queue::fake([QueueCampaignAudits::class]);
        Http::fake([self::OVERPASS => Http::response($this->fixture('overpass-riyadh-restaurants.json'))]);

        $campaign = SearchCampaign::factory()->create(['provider' => 'openstreetmap', 'sources' => null]);

        RunCampaignDiscovery::dispatch($campaign);

        $this->assertSame(['openstreetmap'], $campaign->sourceRuns()->pluck('source')->all());
        $this->assertSame(CampaignStatus::Completed, $campaign->refresh()->status);
    }

    private function startedRun(SearchCampaign $campaign): CampaignSourceRun
    {
        return $this->app->make(CampaignDiscoveryService::class)->start($campaign)[0];
    }
}
