<?php

namespace Tests\Feature\Settings;

use App\Jobs\ImportOvertureExtract;
use App\Jobs\SyncAllThePlacesSpider;
use App\Livewire\Settings\AtpSpiders;
use App\Livewire\Settings\DataSources;
use App\Livewire\Settings\OvertureImports;
use App\Models\AtpSpider;
use App\Models\OvertureImport;
use App\Models\User;
use App\Sources\QuotaManager;
use App\Sources\SourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DataSourceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_pages_require_authentication(): void
    {
        foreach (['settings.sources', 'settings.overture', 'settings.alltheplaces'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_the_data_sources_page_lists_free_and_future_sources(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.sources'))
            ->assertOk()
            ->assertSeeInOrder(['Free sources', 'OpenStreetMap / Overpass', 'Overture Places', 'Future / premium sources', 'Google Places', 'WATHQ'])
            ->assertSee('OSM-derived / same source family as OpenStreetMap');
    }

    public function test_a_saved_api_key_is_encrypted_and_never_sent_back_to_the_browser(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(DataSources::class)
            ->set('credentialInput.geoapify.api_key', 'super-secret-geo-key-4321')
            ->call('saveCredentials', 'geoapify')
            ->assertHasNoErrors();

        $this->assertSame('super-secret-geo-key-4321', $this->app->make(SourceRegistry::class)->credential('geoapify', 'api_key'));

        $this->actingAs($user)->get(route('settings.sources'))
            ->assertOk()
            ->assertDontSee('super-secret-geo-key-4321')
            ->assertSee('••••••4321');

        Livewire::actingAs($user)->test(DataSources::class)
            ->assertSet('credentialInput', [])
            ->assertDontSee('super-secret-geo-key-4321');
    }

    public function test_sources_can_be_disabled_and_future_sources_cannot_be_enabled(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(DataSources::class);

        $component->call('toggle', 'overture');
        $this->assertFalse($this->app->make(SourceRegistry::class)->isEnabled('overture'));

        $component->call('toggle', 'google_places');
        $this->assertFalse($this->app->make(SourceRegistry::class)->isEnabled('google_places'));
    }

    public function test_the_daily_budget_can_be_set(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(DataSources::class)
            ->set('quotaInput.geoapify', '500')
            ->call('saveQuota', 'geoapify')
            ->assertHasNoErrors()
            ->set('quotaInput.geoapify', '-3')
            ->call('saveQuota', 'geoapify')
            ->assertHasErrors(['quotaInput.geoapify' => 'min']);

        $this->assertSame(500, $this->app->make(QuotaManager::class)->dailyLimit('geoapify'));
    }

    public function test_an_uploaded_overture_extract_is_stored_and_queued(): void
    {
        Storage::fake('local');
        Queue::fake([ImportOvertureExtract::class]);

        Livewire::actingAs(User::factory()->create())
            ->test(OvertureImports::class)
            ->set('file', UploadedFile::fake()->createWithContent('riyadh.geojson', (string) file_get_contents(base_path('tests/Fixtures/overture-riyadh.geojson'))))
            ->set('format', 'geojson')
            ->set('city', 'Riyadh')
            ->set('sourceRelease', '2025-09-24.0')
            ->set('bbox', ['min_lon' => '46.5', 'min_lat' => '24.5', 'max_lon' => '46.9', 'max_lat' => '24.9'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.overture'));

        $import = OvertureImport::sole();
        $this->assertSame('upload', $import->mode);
        $this->assertSame('2025-09-24.0', $import->source_release);
        $this->assertEquals(['min_lon' => 46.5, 'min_lat' => 24.5, 'max_lon' => 46.9, 'max_lat' => 24.9], $import->bbox);
        Storage::disk('local')->assertExists($import->file_path);
        Queue::assertPushed(ImportOvertureExtract::class, fn (ImportOvertureExtract $job) => $job->import->is($import));
    }

    public function test_oversized_files_and_unknown_formats_are_rejected(): void
    {
        Storage::fake('local');
        config(['sources.overture.max_upload_mb' => 1]);

        Livewire::actingAs(User::factory()->create())
            ->test(OvertureImports::class)
            ->set('file', UploadedFile::fake()->create('huge.geojson', 2048))
            ->call('save')
            ->assertHasErrors(['file' => 'max'])
            ->set('file', UploadedFile::fake()->create('places.exe', 10))
            ->call('save')
            ->assertHasErrors(['file' => 'extensions'])
            ->set('mode', 'remote')
            ->set('remoteUrl', 'http://insecure.example/x.geojson')
            ->call('save')
            ->assertHasErrors(['remoteUrl']);

        $this->assertSame(0, OvertureImport::count());
    }

    public function test_an_import_can_be_paused_and_resumed(): void
    {
        Queue::fake([ImportOvertureExtract::class]);
        $import = OvertureImport::factory()->create();

        $component = Livewire::actingAs(User::factory()->create())->test(OvertureImports::class);
        $component->call('pause', $import->id);
        $this->assertSame('paused', $import->refresh()->status->value);

        $component->call('resume', $import->id);
        $this->assertSame('pending', $import->refresh()->status->value);
        Queue::assertPushed(ImportOvertureExtract::class);
    }

    public function test_spiders_can_be_added_synced_and_removed(): void
    {
        Queue::fake([SyncAllThePlacesSpider::class]);
        $component = Livewire::actingAs(User::factory()->create())->test(AtpSpiders::class);

        $component->set('spider', 'kfc')->set('brandName', 'KFC')->set('brandWikidata', 'Q524757')->set('country', 'SA')
            ->call('add')->assertHasNoErrors();

        $spider = AtpSpider::sole();
        $this->assertSame('SA', $spider->country_code);

        $component->call('sync', $spider->id);
        Queue::assertPushed(SyncAllThePlacesSpider::class, fn (SyncAllThePlacesSpider $job) => $job->spider->is($spider));

        $component->call('remove', $spider->id);
        $this->assertModelMissing($spider);
    }

    public function test_spider_names_and_wikidata_ids_are_validated(): void
    {
        AtpSpider::factory()->create(['spider' => 'kfc']);

        Livewire::actingAs(User::factory()->create())
            ->test(AtpSpiders::class)
            ->set('spider', 'KFC; rm -rf /')
            ->set('brandName', '')
            ->set('brandWikidata', 'kfc')
            ->call('add')
            ->assertHasErrors(['spider' => 'regex', 'brandName' => 'required', 'brandWikidata' => 'regex'])
            ->set('spider', 'kfc')
            ->call('add')
            ->assertHasErrors(['spider' => 'unique']);
    }
}
