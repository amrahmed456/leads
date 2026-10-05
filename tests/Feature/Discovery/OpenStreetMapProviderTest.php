<?php

namespace Tests\Feature\Discovery;

use App\Discovery\Data\DiscoveredBusiness;
use App\Discovery\Data\DiscoveryQuery;
use App\Discovery\Exceptions\DiscoveryException;
use App\Discovery\Providers\OpenStreetMap\OpenStreetMapProvider;
use App\Enums\BusinessCategory;
use App\Enums\Country;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenStreetMapProviderTest extends TestCase
{
    private const OVERPASS = 'https://overpass.test/api/interpreter';

    public function test_it_converts_overpass_elements_into_discovered_businesses(): void
    {
        Http::fake([self::OVERPASS => Http::response($this->fixture('overpass-riyadh-restaurants.json'))]);

        $result = $this->provider()->discover($this->discoveryQuery(keywords: ['مطعم', 'cafe']));

        $byId = collect($result->businesses)->keyBy('providerId');

        $this->assertSame(['node/1001', 'way/2002', 'node/1004', 'node/1005', 'relation/3008'], $byId->keys()->all());
        // Unnamed fast food, a parking lot, an element without coordinates and a non-object.
        $this->assertSame(4, $result->skipped);
        $this->assertFalse($result->truncated);

        /** @var DiscoveredBusiness $reef */
        $reef = $byId['node/1001'];
        $this->assertSame('openstreetmap', $reef->provider);
        $this->assertSame('مطعم الريف', $reef->name);
        $this->assertSame('Al Reef Restaurant', $reef->alternateName);
        $this->assertSame(BusinessCategory::Restaurant, $reef->category);
        $this->assertSame('restaurant', $reef->type);
        $this->assertSame('SA', $reef->countryCode);
        $this->assertSame('12 Olaya Street, Al Olaya, الرياض, 12211', $reef->address);
        $this->assertSame(24.6898413, $reef->latitude);
        $this->assertSame(46.6864482, $reef->longitude);
        $this->assertSame('011 234 5678', $reef->phone, 'The provider passes raw values; the normalizer cleans them.');
        $this->assertSame('Mo-Su 12:00-24:00', $reef->openingHours);
        $this->assertNull($reef->rating);
        $this->assertNull($reef->reviewsCount);
        $this->assertSame('https://www.openstreetmap.org/node/1001', $reef->sourceUrl);
        $this->assertSame(['type' => 'node', 'id' => 1001, 'tags' => $this->fixture('overpass-riyadh-restaurants.json')['elements'][0]['tags']], $reef->raw);

        // Ways and relations use their centre point.
        $this->assertSame(24.6899, $byId['way/2002']->latitude);
        $this->assertSame(24.6923, $byId['relation/3008']->latitude);

        // Keyword match outside the category keeps its own category.
        $this->assertSame(BusinessCategory::Bakery, $byId['node/1005']->category);
        $this->assertSame(['whatsapp' => '٠٥٥٥١٢٣٤٥٦'], $byId['node/1004']->socialLinks);
    }

    public function test_it_sends_the_generated_query_with_an_identifying_user_agent(): void
    {
        Http::fake([self::OVERPASS => Http::response(['elements' => []])]);

        $this->provider()->discover($this->discoveryQuery());

        Http::assertSent(function (Request $request) {
            return $request->url() === self::OVERPASS
                && $request->method() === 'POST'
                && str_contains($request['data'], 'nwr["amenity"~"^(restaurant|fast_food|food_court)$"](around:2000,24.7136000,46.6753000);')
                && str_starts_with($request->header('User-Agent')[0], 'LeadsDiscovery/');
        });
    }

    public function test_reaching_the_result_limit_is_reported_as_truncated(): void
    {
        config(['discovery.providers.openstreetmap.max_results' => 2]);
        Http::fake([self::OVERPASS => Http::response($this->fixture('overpass-riyadh-restaurants.json'))]);

        $this->assertTrue($this->provider()->discover($this->discoveryQuery())->truncated);
    }

    public function test_server_errors_are_retried_then_reported_as_transient(): void
    {
        Http::fake([self::OVERPASS => Http::response('<html>Gateway Timeout</html>', 504)]);

        $exception = $this->discoverExpectingFailure();

        $this->assertTrue($exception->retryable);
        $this->assertStringContainsString('HTTP 504', $exception->getMessage());
        Http::assertSentCount(3);
    }

    public function test_rate_limiting_is_transient(): void
    {
        Http::fake([self::OVERPASS => Http::response('Too Many Requests', 429)]);

        $this->assertTrue($this->discoverExpectingFailure()->retryable);
    }

    public function test_a_rejected_query_is_permanent_and_not_retried(): void
    {
        Http::fake([self::OVERPASS => Http::response('<p>Error: line 2: parse error</p>', 400)]);

        $exception = $this->discoverExpectingFailure();

        $this->assertFalse($exception->retryable);
        $this->assertStringContainsString('parse error', $exception->getMessage());
        Http::assertSentCount(1);
    }

    public function test_a_malformed_response_is_rejected(): void
    {
        Http::fake([self::OVERPASS => Http::response('<?xml version="1.0"?><osm></osm>', 200, ['Content-Type' => 'application/xml'])]);

        $this->assertStringContainsString('malformed', $this->discoverExpectingFailure()->getMessage());
    }

    public function test_a_response_without_an_elements_list_is_rejected(): void
    {
        Http::fake([self::OVERPASS => Http::response(['elements' => ['a' => 1]])]);

        $this->assertStringContainsString('malformed', $this->discoverExpectingFailure()->getMessage());
    }

    public function test_an_overpass_runtime_error_is_not_treated_as_an_empty_result(): void
    {
        Http::fake([self::OVERPASS => Http::response([
            'elements' => [],
            'remark' => 'runtime error: Query timed out in "query" at line 3 after 120 seconds.',
        ])]);

        $exception = $this->discoverExpectingFailure();

        $this->assertTrue($exception->retryable);
        $this->assertStringContainsString('Query timed out', $exception->getMessage());
    }

    public function test_connection_failures_are_transient(): void
    {
        Http::fake([self::OVERPASS => Http::failedConnection()]);

        $exception = $this->discoverExpectingFailure();

        $this->assertTrue($exception->retryable);
        $this->assertStringContainsString('Could not reach the Overpass API', $exception->getMessage());
    }

    private function discoverExpectingFailure(): DiscoveryException
    {
        try {
            $this->provider()->discover($this->discoveryQuery());
        } catch (DiscoveryException $e) {
            return $e;
        }

        $this->fail('Expected a DiscoveryException.');
    }

    private function provider(): OpenStreetMapProvider
    {
        return $this->app->make(OpenStreetMapProvider::class);
    }

    /**
     * @param  list<string>  $keywords
     */
    private function discoveryQuery(array $keywords = []): DiscoveryQuery
    {
        return new DiscoveryQuery(Country::SaudiArabia, 'Riyadh', 24.7136, 46.6753, 2000, BusinessCategory::Restaurant, $keywords);
    }
}
