<?php

namespace Tests\Feature\Sources;

use App\Enums\BusinessCategory;
use App\Enums\ImportStatus;
use App\Jobs\ImportOvertureExtract;
use App\Models\DataSource;
use App\Models\OvertureImport;
use App\Models\SourcePlace;
use App\Sources\Overture\OvertureDuckDb;
use App\Sources\Overture\OvertureExtractImporter;
use App\Sources\SourceRegistry;
use App\Support\BoundedDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OvertureImportTest extends TestCase
{
    use RefreshDatabase;

    private const RIYADH = ['min_lon' => 46.5, 'min_lat' => 24.5, 'max_lon' => 46.9, 'max_lat' => 24.9];

    public function test_a_geojson_extract_is_imported_with_brand_confidence_and_provenance(): void
    {
        $import = $this->import('overture-riyadh.geojson', 'geojson', self::RIYADH);

        ImportOvertureExtract::dispatchSync($import);

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(6, $import->rows_processed);
        $this->assertSame(3, $import->rows_imported);
        $this->assertSame(2, $import->rows_skipped, 'Low confidence junk and a place outside the area.');
        $this->assertSame(1, $import->rows_invalid, 'A feature without geometry.');

        $kfc = SourcePlace::where('source_record_id', '08f2a1-kfc-king-fahd')->sole();
        $this->assertSame('KFC - King Fahd Road', $kfc->name, 'The place keeps its own name; the brand is separate.');
        $this->assertSame('KFC', $kfc->brand_name);
        $this->assertSame('Q524757', $kfc->brand_wikidata);
        $this->assertSame(BusinessCategory::Restaurant, $kfc->mapped_category);
        $this->assertSame(0.96, $kfc->confidence);
        $this->assertSame('open', $kfc->operating_status);
        $this->assertSame('كنتاكي', $kfc->extra['alternate_name']);
        $this->assertSame(['meta', 'Microsoft'], array_column($kfc->provenance, 'dataset'));
        $this->assertSame($import->id, $kfc->dataset_id);

        // Nested properties exported as JSON strings are decoded.
        $cafe = SourcePlace::where('source_record_id', '08f2a1-reef-cafe')->sole();
        $this->assertSame('Reef Corner Cafe', $cafe->name);
        $this->assertSame(BusinessCategory::Cafe, $cafe->mapped_category);
        $this->assertSame(['instagram' => 'https://www.instagram.com/reefcorner'], $cafe->socials);

        $this->assertSame(BusinessCategory::Restaurant, SourcePlace::where('source_record_id', '08f2a1-atp-sourced')->sole()->mapped_category);
    }

    public function test_an_import_resumes_in_chunks_from_where_it_stopped(): void
    {
        $import = $this->import('overture-riyadh.geojson', 'geojson', self::RIYADH);
        $importer = $this->app->make(OvertureExtractImporter::class);

        $this->assertFalse($importer->process($import, maxRows: 2, maxSeconds: 60));
        $this->assertSame(2, $import->rows_processed);
        $this->assertGreaterThan(0, $import->byte_offset);

        while (! $importer->process($import, maxRows: 2, maxSeconds: 60)) {
            // Each call continues at the saved byte offset.
        }

        $this->assertSame(6, $import->rows_processed);
        $this->assertSame(3, SourcePlace::count());
    }

    public function test_a_queued_import_requeues_itself_until_the_file_is_done(): void
    {
        config(['sources.overture.chunk_rows' => 2]);
        $import = $this->import('overture-riyadh.geojson', 'geojson', self::RIYADH);
        Queue::fake([ImportOvertureExtract::class]);

        (new ImportOvertureExtract($import))->withFakeQueueInteractions()->handle(
            $this->app->make(OvertureExtractImporter::class),
            $this->app->make(BoundedDownloader::class),
            $this->app->make(OvertureDuckDb::class),
            $this->app->make(SourceRegistry::class),
        );

        $this->assertSame(ImportStatus::Running, $import->refresh()->status);
        $this->assertSame(2, $import->rows_processed);
        Queue::assertPushed(ImportOvertureExtract::class, fn (ImportOvertureExtract $job) => $job->import->is($import));
    }

    public function test_an_ndjson_extract_accepts_point_columns_and_wkt_and_counts_malformed_lines(): void
    {
        $import = $this->import('overture-riyadh.ndjson', 'ndjson');

        ImportOvertureExtract::dispatchSync($import);

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(3, $import->rows_imported);
        $this->assertSame(1, $import->rows_invalid);
        $this->assertEqualsWithDelta(24.692, SourcePlace::where('source_record_id', 'nd-3')->sole()->latitude, 0.00001);
        $this->assertSame(BusinessCategory::Dentist, SourcePlace::where('source_record_id', 'nd-2')->sole()->mapped_category);
    }

    public function test_a_csv_extract_with_flattened_columns_is_imported(): void
    {
        $import = $this->import('overture-riyadh.csv', 'csv');

        ImportOvertureExtract::dispatchSync($import);

        $import->refresh();
        $this->assertSame(2, $import->rows_imported);
        $this->assertSame(1, $import->rows_invalid);

        $kudu = SourcePlace::where('source_record_id', 'csv-2')->sole();
        $this->assertSame('Kudu, Olaya', $kudu->name);
        $this->assertSame('Kudu', $kudu->brand_name);
        $this->assertSame('Q6442938', $kudu->brand_wikidata);
        $this->assertSame('https://olayaflowers.sa', SourcePlace::where('source_record_id', 'csv-1')->sole()->website);
    }

    public function test_a_file_that_is_not_an_extract_fails_the_import_without_crashing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('imports/overture/bad.geojson', '{"type": "Topology", "objects": {}}');
        $import = OvertureImport::factory()->create(['format' => 'geojson', 'file_path' => 'imports/overture/bad.geojson']);

        ImportOvertureExtract::dispatchSync($import);

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertStringContainsString('No "features" array found', $import->error_message);
        $this->assertStringContainsString('No "features" array found', DataSource::where('key', 'overture')->sole()->last_error);
    }

    public function test_a_remote_extract_larger_than_the_limit_is_refused(): void
    {
        Storage::fake('local');
        config(['sources.overture.max_remote_mb' => 1]);
        Http::fake(['https://extracts.test/*' => Http::response(str_repeat('x', 1_100_000))]);
        $import = OvertureImport::factory()->create(['mode' => 'remote', 'file_path' => null, 'remote_url' => 'https://extracts.test/riyadh.ndjson']);

        ImportOvertureExtract::dispatchSync($import);

        $this->assertSame(ImportStatus::Failed, $import->refresh()->status);
        $this->assertStringContainsString('above the 1 MB limit', $import->error_message);
        $this->assertSame(0, SourcePlace::count());
    }

    public function test_a_remote_extract_url_on_a_private_network_is_never_requested(): void
    {
        Storage::fake('local');
        Http::fake();
        $import = OvertureImport::factory()->create(['mode' => 'remote', 'file_path' => null, 'remote_url' => 'https://169.254.169.254/latest/meta-data']);

        ImportOvertureExtract::dispatchSync($import);

        $this->assertSame(ImportStatus::Failed, $import->refresh()->status);
        $this->assertStringContainsString('The extract URL is not allowed', $import->error_message);
        Http::assertNothingSent();
    }

    public function test_removing_an_import_deletes_its_places(): void
    {
        $import = $this->import('overture-riyadh.ndjson', 'ndjson');
        ImportOvertureExtract::dispatchSync($import);
        SourcePlace::factory()->overture()->create();

        $this->app->make(OvertureExtractImporter::class)->remove($import->refresh());

        $this->assertModelMissing($import);
        $this->assertSame(1, SourcePlace::count());
        Storage::disk('local')->assertMissing('imports/overture/test.ndjson');
    }

    /**
     * @param  array<string, float>|null  $bbox
     */
    private function import(string $fixture, string $format, ?array $bbox = null): OvertureImport
    {
        Storage::fake('local');
        $path = 'imports/overture/test.'.$format;
        Storage::disk('local')->put($path, (string) file_get_contents(base_path("tests/Fixtures/{$fixture}")));

        return OvertureImport::factory()->create(['format' => $format, 'file_path' => $path, 'bbox' => $bbox]);
    }
}
