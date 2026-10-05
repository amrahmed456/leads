<?php

namespace Tests\Feature\Discovery;

use App\Discovery\BusinessRecorder;
use App\Discovery\Data\DiscoveredBusiness;
use App\Discovery\RecordOutcome;
use App\Enums\BusinessCategory;
use App\Models\Business;
use App\Models\BusinessSource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_business_with_its_provider_source(): void
    {
        $recorded = $this->recorder()->record($this->record(
            phone: '+966112345678',
            website: 'https://alreef.sa',
            raw: ['tags' => ['amenity' => 'restaurant']],
        ), city: 'Riyadh');

        $this->assertSame(RecordOutcome::Created, $recorded->outcome);

        $business = Business::with('sources')->sole();
        $this->assertSame('Al Reef', $business->name);
        $this->assertSame('al reef', $business->name_normalized);
        $this->assertSame(BusinessCategory::Restaurant, $business->category);
        $this->assertSame('Riyadh', $business->city);
        $this->assertSame('+966112345678', $business->phone);
        $this->assertSame('https://alreef.sa', $business->website);
        $this->assertNotNull($business->first_discovered_at);

        $source = $business->sources->sole();
        $this->assertSame('openstreetmap', $source->provider);
        $this->assertSame('node/1', $source->provider_id);
        $this->assertTrue($source->is_primary);
        $this->assertSame(['tags' => ['amenity' => 'restaurant']], $source->raw_data);
    }

    public function test_a_business_without_any_optional_fields_can_be_stored(): void
    {
        $this->recorder()->record(new DiscoveredBusiness('openstreetmap', 'node/9', 'Bare Minimum'));

        $business = Business::sole();
        $this->assertNull($business->category);
        $this->assertNull($business->city);
        $this->assertNull($business->latitude);
        $this->assertNull($business->phone);
        $this->assertNull($business->website);
        $this->assertNull($business->email);
        $this->assertNull($business->rating);
        $this->assertNull($business->reviews_count);
        $this->assertNull($business->social_links);
    }

    public function test_the_same_provider_record_is_never_stored_twice(): void
    {
        $first = $this->recorder()->record($this->record());

        $this->travel(2)->days();

        $second = $this->recorder()->record($this->record(name: 'Al Reef Restaurant', phone: '+966112345678'));

        $this->assertSame(RecordOutcome::Updated, $second->outcome);
        $this->assertTrue($first->business->is($second->business));
        $this->assertSame(1, Business::count());
        $this->assertSame(1, BusinessSource::count());

        // The record's own source refreshes the business; last seen moves, first discovered does not.
        $business = Business::sole();
        $this->assertSame('Al Reef Restaurant', $business->name);
        $this->assertSame('+966112345678', $business->phone);
        $this->assertTrue($business->last_seen_at->isAfter($business->first_discovered_at));
    }

    public function test_the_database_rejects_duplicate_provider_ids(): void
    {
        $business = Business::factory()->create();
        $business->sources()->create(['provider' => 'openstreetmap', 'provider_id' => 'node/1']);

        $this->expectException(UniqueConstraintViolationException::class);

        Business::factory()->create()->sources()->create(['provider' => 'openstreetmap', 'provider_id' => 'node/1']);
    }

    public function test_the_same_provider_id_from_another_provider_is_a_different_record(): void
    {
        $this->recorder()->record($this->record(providerId: 'abc123', latitude: 24.70, longitude: 46.60));
        $this->recorder()->record($this->record(provider: 'google_places', providerId: 'abc123', name: 'Something Else'));

        $this->assertSame(2, BusinessSource::count());
    }

    public function test_a_nearby_record_with_the_same_name_is_merged_into_the_existing_business(): void
    {
        // OSM often maps a business both as a point and as its building outline.
        $this->recorder()->record($this->record(providerId: 'node/1', phone: null, website: 'https://alreef.sa'));
        $merged = $this->recorder()->record($this->record(
            providerId: 'way/2',
            name: 'AL-REEF',
            latitude: 24.69010,
            longitude: 46.68650,
            phone: '+966501234567',
            website: 'https://other.example.com',
        ));

        $this->assertSame(RecordOutcome::Merged, $merged->outcome);
        $this->assertSame(1, Business::count());

        $business = Business::with('sources')->sole();
        $this->assertSame(['node/1', 'way/2'], $business->sources->pluck('provider_id')->sort()->values()->all());
        $this->assertFalse($business->sources->firstWhere('provider_id', 'way/2')->is_primary);
        // A merged source only fills gaps; it does not overwrite the primary source's data.
        $this->assertSame('Al Reef', $business->name);
        $this->assertSame('https://alreef.sa', $business->website);
        $this->assertSame('+966501234567', $business->phone);
    }

    public function test_businesses_with_the_same_name_far_apart_stay_separate(): void
    {
        // Two branches of a chain, ~1 km apart.
        $this->recorder()->record($this->record(providerId: 'node/1', name: 'Kudu', latitude: 24.6900, longitude: 46.6860));
        $second = $this->recorder()->record($this->record(providerId: 'node/2', name: 'Kudu', latitude: 24.6990, longitude: 46.6860));

        $this->assertSame(RecordOutcome::Created, $second->outcome);
        $this->assertSame(2, Business::count());
    }

    public function test_a_missing_value_never_erases_known_data(): void
    {
        $this->recorder()->record($this->record(phone: '+966112345678', website: 'https://alreef.sa'));
        $this->recorder()->record($this->record(phone: null, website: null));

        $business = Business::sole();
        $this->assertSame('+966112345678', $business->phone);
        $this->assertSame('https://alreef.sa', $business->website);
    }

    public function test_the_search_city_is_only_set_when_the_business_has_none(): void
    {
        $this->recorder()->record($this->record(), city: 'Riyadh');
        $this->recorder()->record($this->record(), city: 'Diriyah');

        $this->assertSame('Riyadh', Business::sole()->city);
    }

    private function recorder(): BusinessRecorder
    {
        return $this->app->make(BusinessRecorder::class);
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function record(
        string $provider = 'openstreetmap',
        string $providerId = 'node/1',
        string $name = 'Al Reef',
        float $latitude = 24.6900,
        float $longitude = 46.6865,
        ?string $phone = null,
        ?string $website = null,
        ?array $raw = null,
    ): DiscoveredBusiness {
        return new DiscoveredBusiness(
            provider: $provider,
            providerId: $providerId,
            name: $name,
            category: BusinessCategory::Restaurant,
            type: 'restaurant',
            countryCode: 'SA',
            latitude: $latitude,
            longitude: $longitude,
            phone: $phone,
            website: $website,
            sourceUrl: "https://www.openstreetmap.org/{$providerId}",
            raw: $raw,
        );
    }
}
