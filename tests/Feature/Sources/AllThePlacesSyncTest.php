<?php

namespace Tests\Feature\Sources;

use App\Discovery\BusinessNormalizer;
use App\Discovery\BusinessRecorder;
use App\Discovery\Data\DiscoveredBusiness;
use App\Enums\BrandScope;
use App\Enums\BusinessCategory;
use App\Jobs\RecalculateLeadEligibility;
use App\Jobs\SyncAllThePlacesSpider;
use App\Models\AtpSpider;
use App\Models\Brand;
use App\Models\Business;
use App\Models\SourcePlace;
use App\Sources\AllThePlaces\AllThePlacesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AllThePlacesSyncTest extends TestCase
{
    use RefreshDatabase;

    private const LATEST = 'https://data.alltheplaces.xyz/runs/latest.json';

    private const SPIDER = 'https://alltheplaces-data.openaddresses.io/runs/2026-10-01-13-32-15/output/kfc.geojson';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_spider_sync_downloads_only_that_spider_and_keeps_our_countries(): void
    {
        $this->fakeAllThePlaces();
        $spider = AtpSpider::factory()->create(['spider' => 'kfc', 'brand_name' => 'KFC']);

        SyncAllThePlacesSpider::dispatchSync($spider);

        $spider->refresh();
        $this->assertSame(6, $spider->feature_count);
        $this->assertSame(4, $spider->stored_count, 'Saudi and Emirati branches; the US branch and a malformed feature are not stored.');
        $this->assertNull($spider->last_error);
        $this->assertSame('2026-10-01-13-32-15', $spider->last_run_id);
        $this->assertSame(self::SPIDER, $spider->output_url);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), 'output.zip'));

        $branch = SourcePlace::where('source_record_id', 'kfc/SA-001')->sole();
        $this->assertSame('alltheplaces', $branch->source);
        $this->assertSame('KFC King Fahd Road', $branch->name);
        $this->assertSame('King Fahd Road', $branch->branch);
        $this->assertSame(BusinessCategory::Restaurant, $branch->mapped_category);
        $this->assertSame('https://www.kfc.com.sa/locations', $branch->source_url);

        $locationOnly = SourcePlace::where('source_record_id', 'kfc/SA-003')->sole();
        $this->assertSame('KFC – Jeddah, Red Sea Mall', $locationOnly->name, 'A branch named only by its location keeps the brand in its name.');
        $this->assertSame('Jeddah, Red Sea Mall', $locationOnly->branch);
    }

    public function test_the_brand_records_its_branch_count_and_becomes_international(): void
    {
        $this->fakeAllThePlaces();

        SyncAllThePlacesSpider::dispatchSync(AtpSpider::factory()->create(['spider' => 'kfc', 'brand_name' => 'KFC']));

        $brand = Brand::where('wikidata_id', 'Q524757')->sole();
        $this->assertSame('KFC', $brand->name);
        $this->assertSame(5, $brand->location_count);
        $this->assertSame(BrandScope::International, $brand->scope, 'Branches in Saudi Arabia, the UAE and the US.');
    }

    public function test_matching_businesses_gain_first_party_branch_evidence_and_are_re_evaluated(): void
    {
        $this->fakeAllThePlaces();
        $kfc = $this->business('KFC', 24.71365, 46.67535, 'node/1');
        $unrelated = $this->business('Olaya Shawarma', 24.6906, 46.6802, 'node/2');
        Queue::fake([RecalculateLeadEligibility::class]);

        SyncAllThePlacesSpider::dispatchSync(AtpSpider::factory()->create(['spider' => 'kfc', 'brand_name' => 'KFC']));

        $source = $kfc->sources()->where('provider', 'alltheplaces')->sole();
        $this->assertSame('first_party', $source->independence_group);
        $this->assertSame('verification', $source->source_role->value);
        $this->assertNotEmpty($source->match_reasons);
        $this->assertFalse($unrelated->sources()->where('provider', 'alltheplaces')->exists(), 'A different business next to a branch is not linked.');
        $this->assertSame(1, AtpSpider::sole()->matched_count);
        Queue::assertPushed(RecalculateLeadEligibility::class, fn (RecalculateLeadEligibility $job) => $job->businessIds === [$kfc->id]);
    }

    public function test_branches_no_longer_listed_are_removed_on_the_next_sync(): void
    {
        $spider = AtpSpider::factory()->create(['spider' => 'kfc', 'brand_name' => 'KFC']);
        SourcePlace::factory()->storeLocator($spider)->create(['source_record_id' => 'kfc/CLOSED-1', 'observed_at' => now()->subWeek()]);
        $this->travel(1)->minutes();
        $this->fakeAllThePlaces();

        SyncAllThePlacesSpider::dispatchSync($spider);

        $this->assertFalse(SourcePlace::where('source_record_id', 'kfc/CLOSED-1')->exists());
        $this->assertSame(1, $spider->refresh()->last_result['removed']);
    }

    public function test_a_failed_download_is_recorded_on_the_spider(): void
    {
        Http::fake([
            self::LATEST => Http::response(['run_id' => '2026-10-01-13-32-15', 'output_url' => 'https://alltheplaces-data.openaddresses.io/runs/2026-10-01-13-32-15/output.zip']),
            self::SPIDER => Http::response('Not Found', 404),
        ]);
        $spider = AtpSpider::factory()->create(['spider' => 'kfc', 'brand_name' => 'KFC']);

        SyncAllThePlacesSpider::dispatchSync($spider);

        $this->assertStringContainsString('HTTP 404', $spider->refresh()->last_error);
        $this->assertNull($spider->last_success_at);
        $this->assertSame(0, SourcePlace::count());
    }

    public function test_invalid_spider_names_are_never_requested(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->app->make(AllThePlacesClient::class)->spiderUrl(['run_id' => 'x', 'output_url' => 'https://example.test/runs/x/output.zip'], '../../etc/passwd');
    }

    private function fakeAllThePlaces(): void
    {
        Http::fake([
            self::LATEST => Http::response(['run_id' => '2026-10-01-13-32-15', 'output_url' => 'https://alltheplaces-data.openaddresses.io/runs/2026-10-01-13-32-15/output.zip']),
            self::SPIDER => Http::response((string) file_get_contents(base_path('tests/Fixtures/atp-kfc.geojson'))),
        ]);
    }

    private function business(string $name, float $latitude, float $longitude, string $osmId): Business
    {
        $data = $this->app->make(BusinessNormalizer::class)->normalize(new DiscoveredBusiness(
            provider: 'openstreetmap', providerId: $osmId, name: $name, category: BusinessCategory::Restaurant,
            countryCode: 'SA', latitude: $latitude, longitude: $longitude,
        ));

        return $this->app->make(BusinessRecorder::class)->record($data, 'Riyadh')->business;
    }
}
