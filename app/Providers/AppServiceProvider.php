<?php

namespace App\Providers;

use App\Discovery\Contracts\GeocoderInterface;
use App\Discovery\DiscoveryProviderRegistry;
use App\Discovery\Geocoding\GeoNamesFirstGeocoder;
use App\Discovery\Geocoding\NominatimGeocoder;
use App\Discovery\Providers\AllThePlaces\AllThePlacesProvider;
use App\Discovery\Providers\Geoapify\GeoapifyFeatureMapper;
use App\Discovery\Providers\Geoapify\GeoapifyProvider;
use App\Discovery\Providers\LocalPlaces\LocalPlaceSearch;
use App\Discovery\Providers\OpenStreetMap\OpenStreetMapProvider;
use App\Discovery\Providers\OpenStreetMap\OsmElementMapper;
use App\Discovery\Providers\OpenStreetMap\OverpassQueryBuilder;
use App\Discovery\Providers\Overture\OvertureProvider;
use App\Identity\BranchCounter;
use App\Identity\Brands\BrandEnricher;
use App\Identity\Brands\BrandRegistry;
use App\Identity\Brands\NsiBrandImporter;
use App\Identity\Brands\WikidataClient;
use App\Identity\EntityClassifier;
use App\Identity\EntityMatcher;
use App\Identity\Evidence\EvidenceReconciler;
use App\Identity\Evidence\EvidenceRecorder;
use App\Identity\IndependenceResolver;
use App\Identity\SourcePlaceLinker;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Organization;
use App\Models\User;
use App\Sources\AllThePlaces\AllThePlacesClient;
use App\Sources\AllThePlaces\AtpFeatureMapper;
use App\Sources\AllThePlaces\AtpSpiderSynchronizer;
use App\Sources\GeoNames\CityResolver;
use App\Sources\GeoNames\GeoNamesImporter;
use App\Sources\OpenAddresses\AddressContextService;
use App\Sources\Overture\OvertureDuckDb;
use App\Sources\Overture\OvertureExtractImporter;
use App\Sources\Overture\OvertureRecordMapper;
use App\Sources\QuotaManager;
use App\Sources\SourceRegistry;
use App\Support\BoundedDownloader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerSources();
        $this->registerDiscovery();
        $this->registerIdentity();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface N+1 queries and mass-assignment mistakes during development.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Stable names for the brand / organization graph edges.
        Relation::morphMap([
            'business' => Business::class,
            'brand' => Brand::class,
            'organization' => Organization::class,
        ]);

        // Data source settings, credentials and imports. Single-user application:
        // every signed-in user is the admin; the gate centralizes the rule.
        Gate::define('manage-data-sources', fn (User $user): bool => true);

        // Overture extracts can exceed Livewire's 12 MB default; keep a hard, configurable cap.
        config([
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.((int) config('sources.overture.max_upload_mb') * 1024)],
            'livewire.temporary_file_upload.max_upload_time' => 15,
        ]);

        if ($caBundle = config('discovery.ca_bundle')) {
            Http::globalOptions(['verify' => $caBundle]);
        }
    }

    private function registerSources(): void
    {
        $this->app->singleton(SourceRegistry::class, fn () => new SourceRegistry((array) config('sources.catalog', [])));

        $this->app->bind(BoundedDownloader::class, fn () => new BoundedDownloader($this->userAgent()));

        $this->app->bind(OvertureExtractImporter::class, fn (Application $app) => new OvertureExtractImporter(
            $app->make(OvertureRecordMapper::class),
            disk: (string) config('sources.overture.disk'),
            minConfidence: (float) config('sources.overture.min_confidence'),
        ));

        $this->app->bind(OvertureDuckDb::class, fn () => new OvertureDuckDb((array) config('sources.overture.duckdb')));

        $this->app->bind(CityResolver::class, fn (Application $app) => new CityResolver(
            $app->make(SourceRegistry::class),
            $app->make(QuotaManager::class),
            (string) config('sources.geonames.api_url'),
        ));

        $this->app->bind(GeoNamesImporter::class, fn () => new GeoNamesImporter((array) config('sources.geonames.countries')));

        $this->app->bind(AddressContextService::class, fn (Application $app) => new AddressContextService(
            $app->make(EvidenceRecorder::class),
            (int) config('sources.openaddresses.match_distance_meters'),
        ));

        $this->app->bind(AllThePlacesClient::class, fn () => new AllThePlacesClient(
            (string) config('sources.alltheplaces.latest_run_url'),
            $this->userAgent(),
        ));

        $this->app->bind(AtpSpiderSynchronizer::class, fn (Application $app) => new AtpSpiderSynchronizer(
            $app->make(AllThePlacesClient::class),
            $app->make(BoundedDownloader::class),
            $app->make(AtpFeatureMapper::class),
            $app->make(BrandRegistry::class),
            $app->make(SourcePlaceLinker::class),
            $app->make(QuotaManager::class),
            $app->make(SourceRegistry::class),
            countries: (array) config('sources.alltheplaces.countries'),
            maxBytes: (int) config('sources.alltheplaces.max_download_mb') * 1_048_576,
        ));
    }

    private function registerDiscovery(): void
    {
        $this->app->singleton(DiscoveryProviderRegistry::class, fn (Application $app) => new DiscoveryProviderRegistry(
            $app,
            (array) config('discovery.providers', []),
            $app->make(SourceRegistry::class),
        ));

        $this->app->bind(OpenStreetMapProvider::class, fn (Application $app) => new OpenStreetMapProvider(
            $app->make(OverpassQueryBuilder::class),
            $app->make(OsmElementMapper::class),
            endpoint: (string) config('discovery.providers.openstreetmap.overpass_url'),
            queryTimeout: (int) config('discovery.providers.openstreetmap.query_timeout', 120),
            maxResults: (int) config('discovery.providers.openstreetmap.max_results', 3000),
            userAgent: $this->userAgent(),
        ));

        $this->app->bind(OvertureProvider::class, fn (Application $app) => new OvertureProvider(
            $app->make(LocalPlaceSearch::class),
            (int) config('discovery.providers.overture.max_results', 3000),
        ));

        $this->app->bind(AllThePlacesProvider::class, fn (Application $app) => new AllThePlacesProvider(
            $app->make(LocalPlaceSearch::class),
            (int) config('discovery.providers.alltheplaces.max_results', 3000),
        ));

        $this->app->bind(GeoapifyFeatureMapper::class, fn () => new GeoapifyFeatureMapper((array) config('sources.geoapify.categories')));

        $this->app->bind(GeoapifyProvider::class, fn (Application $app) => new GeoapifyProvider(
            $app->make(GeoapifyFeatureMapper::class),
            $app->make(SourceRegistry::class),
            $app->make(QuotaManager::class),
            baseUrl: (string) config('sources.geoapify.base_url'),
            categories: (array) config('sources.geoapify.categories'),
            pageSize: (int) config('sources.geoapify.page_size'),
            maxResults: (int) config('sources.geoapify.max_results'),
            placesPerCredit: (int) config('sources.geoapify.places_per_credit'),
            userAgent: $this->userAgent(),
        ));

        // GeoNames (local, then its web service when configured) before Nominatim.
        $this->app->bind(GeocoderInterface::class, fn (Application $app) => new GeoNamesFirstGeocoder(
            $app->make(CityResolver::class),
            new NominatimGeocoder(
                baseUrl: (string) config('discovery.geocoder.nominatim_url'),
                userAgent: $this->userAgent(),
                contactEmail: config('discovery.contact_email') ?: null,
                cacheDays: (int) config('discovery.geocoder.cache_days', 30),
            ),
        ));
    }

    private function registerIdentity(): void
    {
        $this->app->bind(EntityMatcher::class, fn () => new EntityMatcher(
            (array) config('intelligence.identity.matching'),
            [...array_keys((array) config('intelligence.entity.aggregators')), ...array_keys((array) config('intelligence.entity.directories'))],
        ));

        $this->app->bind(IndependenceResolver::class, fn (Application $app) => new IndependenceResolver(
            $app->make(SourceRegistry::class),
            (array) config('intelligence.identity.overture_provenance_groups'),
        ));

        $this->app->bind(EvidenceReconciler::class, fn () => new EvidenceReconciler(
            (array) config('intelligence.identity.source_priority'),
            (int) config('intelligence.identity.stale_after_days'),
        ));

        $this->app->bind(EntityClassifier::class, fn (Application $app) => new EntityClassifier(
            (array) config('intelligence.entity'),
            $app->make(BranchCounter::class),
            $app->make(SourceRegistry::class),
        ));

        $this->app->bind(BrandRegistry::class, fn () => new BrandRegistry(
            (int) config('intelligence.entity.brand_scope.national_min_locations'),
        ));

        $this->app->bind(NsiBrandImporter::class, fn (Application $app) => new NsiBrandImporter(
            $app->make(BrandRegistry::class),
            $app->make(BoundedDownloader::class),
            $app->make(QuotaManager::class),
            $app->make(SourceRegistry::class),
            baseUrl: (string) config('sources.nsi.base_url'),
            categories: (array) config('sources.nsi.categories'),
            countries: (array) config('sources.nsi.countries'),
            enterpriseCategories: (array) config('intelligence.entity.enterprise.nsi_categories'),
            maxFileBytes: (int) config('sources.nsi.max_file_mb') * 1_048_576,
        ));

        $this->app->bind(WikidataClient::class, fn (Application $app) => new WikidataClient(
            $app->make(SourceRegistry::class),
            $app->make(QuotaManager::class),
            entityUrl: (string) config('sources.wikidata.entity_url'),
            cacheDays: (int) config('sources.wikidata.cache_days'),
            userAgent: $this->userAgent(),
        ));

        $this->app->bind(BrandEnricher::class, fn (Application $app) => new BrandEnricher(
            $app->make(WikidataClient::class),
            $app->make(BrandRegistry::class),
            enterpriseMinEmployees: (int) config('intelligence.entity.enterprise.min_employees'),
            cacheDays: (int) config('sources.wikidata.cache_days'),
            enterpriseCategories: (array) config('intelligence.entity.enterprise.nsi_categories'),
        ));
    }

    private function userAgent(): string
    {
        $userAgent = (string) config('discovery.user_agent');
        $contact = config('discovery.contact_email');

        return $contact ? "{$userAgent} contact: {$contact}" : $userAgent;
    }
}
