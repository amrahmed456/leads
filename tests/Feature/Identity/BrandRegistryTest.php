<?php

namespace Tests\Feature\Identity;

use App\Enums\BrandScope;
use App\Enums\EntityType;
use App\Enums\RelationshipType;
use App\Identity\Brands\BrandEnricher;
use App\Identity\Brands\BrandRegistry;
use App\Identity\Brands\NsiBrandImporter;
use App\Identity\Brands\WikidataClient;
use App\Models\Brand;
use App\Models\EntityRelationship;
use App\Models\WikidataEntity;
use App\Sources\SourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const WIKIDATA = 'https://www.wikidata.org/wiki/Special:EntityData/*';

    public function test_nsi_brands_active_in_our_countries_are_imported_with_their_names(): void
    {
        $count = $this->importNsi();

        $this->assertSame(3, $count, 'KFC (worldwide), Al Baik and Kudu; not the US-only brand or the nameless item.');

        $kfc = Brand::where('wikidata_id', 'Q524757')->sole();
        $this->assertSame('KFC', $kfc->name);
        $this->assertSame('kfc-658eea', $kfc->nsi_id);
        $this->assertSame(BrandScope::International, $kfc->scope);
        $this->assertSame(['brands/amenity/fast_food'], $kfc->categories);
        $this->assertEqualsCanonicalizing(['kfc', 'كنتاكي', 'kentucky fried chicken', 'kfc restaurant'], $kfc->aliases()->pluck('alias_normalized')->all());

        $this->assertSame(BrandScope::National, Brand::where('name', 'Kudu')->sole()->scope);
        $this->assertSame(BrandScope::International, Brand::where('name', 'Al Baik')->sole()->scope);
        $this->assertFalse(Brand::where('name', "Culver's")->exists());
    }

    public function test_brands_in_enterprise_categories_are_marked_enterprises(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('bank.json', json_encode(['items' => [[
            'displayName' => 'Al Rajhi Bank', 'id' => 'alrajhi', 'locationSet' => ['include' => ['sa']],
            'tags' => ['amenity' => 'bank', 'brand' => 'Al Rajhi Bank', 'brand:wikidata' => 'Q2638225'],
        ]]]));

        $this->app->make(NsiBrandImporter::class)->importFile(Storage::disk('local')->path('bank.json'), 'brands/amenity/bank');

        $this->assertSame(EntityType::Enterprise, Brand::sole()->entity_type);
    }

    public function test_a_refresh_downloads_each_category_file_and_survives_a_failing_one(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://raw.githubusercontent.com/osmlab/name-suggestion-index/main/data/brands/amenity/fast_food.json' => Http::response((string) file_get_contents(base_path('tests/Fixtures/nsi-fast_food.json'))),
            'https://raw.githubusercontent.com/osmlab/name-suggestion-index/main/data/brands/amenity/cafe.json' => Http::response('Not Found', 404),
        ]);

        $summary = $this->app->make(NsiBrandImporter::class)->refresh(['brands/amenity/fast_food', 'brands/amenity/cafe']);

        $this->assertSame(1, $summary['files']);
        $this->assertSame(3, $summary['brands']);
        $this->assertArrayHasKey('brands/amenity/cafe', $summary['failed']);
    }

    public function test_name_matching_is_conservative(): void
    {
        $this->importNsi();
        $registry = $this->app->make(BrandRegistry::class);

        $this->assertSame('KFC', $registry->findByName('KFC - King Fahd Road', 'SA')?->name);
        $this->assertSame('KFC', $registry->findByName('كنتاكي', 'SA')?->name);
        $this->assertSame('Al Baik', $registry->findByName('البيك', 'SA')?->name);
        $this->assertNull($registry->findByName('Kudu Burger House', 'SA'), 'A longer name only resembles the brand.');
        $this->assertSame(['Kudu'], array_map(fn (Brand $brand): string => $brand->name, $registry->findContainedIn('Kudu Burger House')));
    }

    public function test_wikidata_lookups_are_cached(): void
    {
        Http::fake([self::WIKIDATA => Http::response($this->fixture('wikidata-Q524757.json'))]);
        $client = $this->app->make(WikidataClient::class);

        $first = $client->entity('Q524757');
        $second = $client->entity('q524757');

        $this->assertSame('KFC', $first->label);
        $this->assertSame('https://www.kfc.com/', $first->official_website);
        $this->assertSame(['Q1153'], $first->parent_ids);
        $this->assertSame(['Q30', 'Q851'], $second->country_ids);
        Http::assertSentCount(1);
    }

    public function test_a_failing_wikidata_lookup_returns_nothing_and_is_recorded(): void
    {
        Http::fake([self::WIKIDATA => Http::response('busy', 503)]);

        $this->assertNull($this->app->make(WikidataClient::class)->entity('Q524757'));
        $this->assertSame(0, WikidataEntity::count());
        $this->assertStringContainsString('HTTP 503', $this->app->make(SourceRegistry::class)->state('wikidata')->last_error);
    }

    public function test_enrichment_adds_the_official_domain_aliases_and_parent_organization(): void
    {
        $this->importNsi();
        Http::fake([
            'https://www.wikidata.org/wiki/Special:EntityData/Q524757.json' => Http::response($this->fixture('wikidata-Q524757.json')),
            'https://www.wikidata.org/wiki/Special:EntityData/Q1153.json' => Http::response(['entities' => ['Q1153' => [
                'labels' => ['en' => ['value' => 'Yum! Brands']],
                'claims' => ['P1128' => [['mainsnak' => ['datavalue' => ['value' => ['amount' => '+36000']]]]]],
            ]]]),
        ]);
        $kfc = Brand::where('wikidata_id', 'Q524757')->sole();

        $this->app->make(BrandEnricher::class)->enrich($kfc);

        $kfc->refresh();
        $this->assertSame(['kfc.com'], $kfc->official_domains);
        $this->assertTrue($kfc->aliases()->where('alias', 'Kentucky Fried Chicken')->exists());
        $this->assertSame('Yum! Brands', $kfc->organization->name);
        $this->assertSame(36000, $kfc->organization->employees);
        $this->assertNotNull($kfc->wikidata_fetched_at);
        $this->assertNull($kfc->entity_type, 'A restaurant chain with many employees is still a chain, not an enterprise.');
        $this->assertTrue(EntityRelationship::where('relationship', RelationshipType::OwnedBy)->whereMorphedTo('subject', $kfc)->exists());
        $this->assertFalse($this->app->make(BrandEnricher::class)->needsEnrichment($kfc));
    }

    public function test_a_failed_enrichment_is_not_retried_on_every_evaluation(): void
    {
        $this->importNsi();
        Http::fake([self::WIKIDATA => Http::response('busy', 503)]);
        $kfc = Brand::where('wikidata_id', 'Q524757')->sole();
        $enricher = $this->app->make(BrandEnricher::class);

        $this->assertFalse($enricher->enrich($kfc));
        $this->assertFalse($enricher->needsEnrichment($kfc->refresh()), 'Wait a day before trying again.');

        $this->travel(25)->hours();
        $this->assertTrue($enricher->needsEnrichment($kfc));
    }

    private function importNsi(): int
    {
        return $this->app->make(NsiBrandImporter::class)->importFile(base_path('tests/Fixtures/nsi-fast_food.json'), 'brands/amenity/fast_food');
    }
}
