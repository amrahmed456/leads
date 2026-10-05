<?php

namespace App\Providers;

use App\Discovery\BusinessRecorder;
use App\Discovery\Contracts\GeocoderInterface;
use App\Discovery\DiscoveryProviderRegistry;
use App\Discovery\Geocoding\NominatimGeocoder;
use App\Discovery\Providers\OpenStreetMap\OpenStreetMapProvider;
use App\Discovery\Providers\OpenStreetMap\OsmElementMapper;
use App\Discovery\Providers\OpenStreetMap\OverpassQueryBuilder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DiscoveryProviderRegistry::class, fn (Application $app) => new DiscoveryProviderRegistry(
            $app,
            (array) config('discovery.providers', []),
        ));

        $this->app->bind(OpenStreetMapProvider::class, fn (Application $app) => new OpenStreetMapProvider(
            $app->make(OverpassQueryBuilder::class),
            $app->make(OsmElementMapper::class),
            endpoint: (string) config('discovery.providers.openstreetmap.overpass_url'),
            queryTimeout: (int) config('discovery.providers.openstreetmap.query_timeout', 120),
            maxResults: (int) config('discovery.providers.openstreetmap.max_results', 3000),
            userAgent: $this->userAgent(),
        ));

        $this->app->bind(GeocoderInterface::class, fn () => new NominatimGeocoder(
            baseUrl: (string) config('discovery.geocoder.nominatim_url'),
            userAgent: $this->userAgent(),
            contactEmail: config('discovery.contact_email') ?: null,
            cacheDays: (int) config('discovery.geocoder.cache_days', 30),
        ));

        $this->app->bind(BusinessRecorder::class, fn () => new BusinessRecorder(
            (int) config('discovery.dedupe_distance_meters', 75),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface N+1 queries and mass-assignment mistakes during development.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        if ($caBundle = config('discovery.ca_bundle')) {
            Http::globalOptions(['verify' => $caBundle]);
        }
    }

    private function userAgent(): string
    {
        $userAgent = (string) config('discovery.user_agent');
        $contact = config('discovery.contact_email');

        return $contact ? "{$userAgent} contact: {$contact}" : $userAgent;
    }
}
