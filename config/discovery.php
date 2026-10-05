<?php

use App\Discovery\Providers\OpenStreetMap\OpenStreetMapProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Discovery Provider
    |--------------------------------------------------------------------------
    |
    | The provider pre-selected when creating a new search campaign. It must be
    | one of the enabled providers listed below.
    |
    */

    'default_provider' => env('DISCOVERY_DEFAULT_PROVIDER', 'openstreetmap'),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Discovery jobs call slow external APIs, so they always run on a queue.
    | The worker must listen on this queue (php artisan queue:work --queue=...).
    |
    */

    'queue' => env('DISCOVERY_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Search Radius Limits (kilometres)
    |--------------------------------------------------------------------------
    */

    'radius' => [
        'min_km' => 0.5,
        'max_km' => (float) env('DISCOVERY_MAX_RADIUS_KM', 50),
        'default_km' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication
    |--------------------------------------------------------------------------
    |
    | A newly discovered record that is not yet linked to a business is merged
    | into an existing business when both have the same normalized name and
    | lie within this distance of each other (in metres).
    |
    */

    'dedupe_distance_meters' => (int) env('DISCOVERY_DEDUPE_DISTANCE_METERS', 75),

    /*
    |--------------------------------------------------------------------------
    | HTTP Identification
    |--------------------------------------------------------------------------
    |
    | The OpenStreetMap usage policies require a User-Agent that identifies the
    | application. A contact e-mail is optional but recommended by Nominatim.
    |
    */

    'user_agent' => env('DISCOVERY_USER_AGENT', 'LeadsDiscovery/1.0 (personal business research tool)'),

    'contact_email' => env('DISCOVERY_CONTACT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | TLS Certificate Bundle
    |--------------------------------------------------------------------------
    |
    | Path to a CA bundle (cacert.pem) used to verify HTTPS connections. Only
    | needed when PHP's curl.cainfo is missing or wrong, e.g. on some Laragon
    | installs: C:\laragon\etc\ssl\cacert.pem. Certificate verification is
    | always on.
    |
    */

    'ca_bundle' => env('HTTP_CA_BUNDLE'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Every provider implements BusinessDiscoveryProviderInterface. Add a new
    | provider by implementing the interface and registering it here.
    |
    */

    'providers' => [

        'openstreetmap' => [
            'class' => OpenStreetMapProvider::class,
            'label' => 'OpenStreetMap',
            'enabled' => (bool) env('OSM_ENABLED', true),
            'overpass_url' => env('OSM_OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
            // Server-side query timeout sent to Overpass, in seconds.
            'query_timeout' => (int) env('OSM_OVERPASS_TIMEOUT', 120),
            // Hard cap on the number of elements returned for one campaign run.
            'max_results' => (int) env('OSM_MAX_RESULTS', 3000),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Geocoding
    |--------------------------------------------------------------------------
    |
    | Used to resolve a campaign's city/area to coordinates when the user does
    | not provide latitude/longitude. Results are cached, as required by the
    | Nominatim usage policy.
    |
    */

    'geocoder' => [
        'nominatim_url' => env('OSM_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'cache_days' => (int) env('GEOCODER_CACHE_DAYS', 30),
    ],

];
