<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Imports, spider syncs, brand registry refreshes and identity analysis run
    | as database-queue jobs. The cPanel cron worker processes this queue.
    |
    */

    'queue' => env('SOURCES_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Source Catalog
    |--------------------------------------------------------------------------
    |
    | Every data source the system knows, with the metadata needed to reason
    | about it. Runtime state (enabled flag, encrypted credentials, health,
    | usage) lives in the data_sources and source_usage tables and is managed
    | under Settings → Data Sources.
    |
    | independence_group: sources backed by the same underlying dataset share
    | a group and never count as independent confirmation of each other
    | (OpenStreetMap and Geoapify are both "osm_family").
    |
    | roles: discovery, verification, enrichment, brand_intelligence,
    | geographic_context, address_context.
    |
    | implemented: false for future sources; they are listed but can never be
    | enabled or called.
    |
    */

    'catalog' => [

        'openstreetmap' => [
            'label' => 'OpenStreetMap / Overpass',
            'roles' => ['discovery'],
            'tier' => 'free',
            'credentials' => [],
            'coverage' => 'Global; community-maintained, density varies by city',
            'persistable' => true,
            'freshness' => 'Live (minutes behind edits)',
            'independence_group' => 'osm_family',
            'rate_limit' => ['description' => 'Fair use; one heavy query at a time', 'daily_quota' => null, 'unit' => 'requests'],
            'capabilities' => ['radius_search', 'category_search', 'keyword_search', 'brand_tags'],
            'docs_url' => 'https://wiki.openstreetmap.org/wiki/Overpass_API',
            'default_enabled' => true,
            'implemented' => true,
        ],

        'overture' => [
            'label' => 'Overture Places',
            'roles' => ['discovery', 'enrichment', 'brand_intelligence'],
            'tier' => 'free',
            'credentials' => [],
            'coverage' => 'Global; only the areas covered by imported extracts are searchable',
            'persistable' => true,
            'freshness' => 'Monthly releases',
            'independence_group' => 'overture',
            'rate_limit' => ['description' => 'Local data; no external requests during discovery', 'daily_quota' => null, 'unit' => 'rows'],
            'capabilities' => ['radius_search', 'category_search', 'keyword_search', 'brand', 'confidence', 'provenance'],
            'docs_url' => 'https://docs.overturemaps.org/guides/places/',
            'default_enabled' => true,
            'implemented' => true,
        ],

        'geoapify' => [
            'label' => 'Geoapify Places',
            'roles' => ['discovery', 'enrichment'],
            'tier' => 'free',
            'credentials' => ['api_key' => 'API key'],
            'env_credentials' => ['api_key' => env('GEOAPIFY_API_KEY')],
            'coverage' => 'Global (OpenStreetMap-derived)',
            'persistable' => true,
            'freshness' => 'Weekly OSM imports',
            // Geoapify serves OpenStreetMap data: it can never confirm an OSM record independently.
            'independence_group' => 'osm_family',
            'rate_limit' => ['description' => 'Free plan: 3,000 credits per day', 'daily_quota' => (int) env('GEOAPIFY_DAILY_CREDITS', 3000), 'unit' => 'credits'],
            'capabilities' => ['radius_search', 'category_search', 'osm_ids', 'brand'],
            'docs_url' => 'https://apidocs.geoapify.com/docs/places/',
            'notes' => 'OSM-derived / same source family as OpenStreetMap. Never counted as independent evidence.',
            'default_enabled' => true,
            'implemented' => true,
        ],

        'alltheplaces' => [
            'label' => 'AllThePlaces',
            'roles' => ['verification', 'discovery', 'brand_intelligence'],
            'tier' => 'free',
            'credentials' => [],
            'coverage' => 'Brands with a configured spider (store locators of chains)',
            'persistable' => true,
            'freshness' => 'Weekly spider runs',
            'independence_group' => 'first_party',
            'rate_limit' => ['description' => 'One GeoJSON download per spider per sync', 'daily_quota' => (int) env('ATP_DAILY_DOWNLOADS', 50), 'unit' => 'downloads'],
            'capabilities' => ['first_party_branches', 'brand', 'branch_count'],
            'docs_url' => 'https://www.alltheplaces.xyz/',
            'notes' => 'First-party branch evidence from official store locators. Only configured spiders are downloaded; never the global archive.',
            'default_enabled' => true,
            'implemented' => true,
        ],

        'website' => [
            'label' => 'Website / Schema.org',
            'roles' => ['verification', 'brand_intelligence'],
            'tier' => 'free',
            'credentials' => [],
            'coverage' => 'Businesses with a website',
            'persistable' => true,
            'freshness' => 'Audit date (30-day reuse)',
            'independence_group' => 'first_party_web',
            'rate_limit' => ['description' => 'Homepage only; audits per minute in config/auditing.php', 'daily_quota' => null, 'unit' => 'requests'],
            'capabilities' => ['json_ld', 'microdata', 'rdfa', 'parent_organization', 'branch_of', 'contact_details'],
            'docs_url' => 'https://schema.org/LocalBusiness',
            'default_enabled' => true,
            'implemented' => true,
        ],

        'nsi' => [
            'label' => 'Name Suggestion Index',
            'roles' => ['brand_intelligence'],
            'tier' => 'free',
            'credentials' => [],
            'coverage' => 'Global brand registry (chains and operators)',
            'persistable' => true,
            'freshness' => 'Refreshed weekly from the published index',
            'independence_group' => 'brand_registry',
            'rate_limit' => ['description' => 'A few category files per refresh', 'daily_quota' => 200, 'unit' => 'requests'],
            'capabilities' => ['brand_names', 'brand_wikidata', 'location_sets'],
            'docs_url' => 'https://nsi.guide/',
            'default_enabled' => true,
            'implemented' => true,
        ],

        'wikidata' => [
            'label' => 'Wikidata',
            'roles' => ['brand_intelligence'],
            'tier' => 'free',
            'credentials' => [],
            'coverage' => 'Brands and organizations with a Wikidata ID',
            'persistable' => true,
            'freshness' => 'Cached 90 days',
            'independence_group' => 'brand_registry',
            'rate_limit' => ['description' => 'Selective: only known brand IDs, cached', 'daily_quota' => (int) env('WIKIDATA_DAILY_REQUESTS', 500), 'unit' => 'requests'],
            'capabilities' => ['canonical_identity', 'official_website', 'aliases', 'parent_organization'],
            'docs_url' => 'https://www.wikidata.org/wiki/Wikidata:Data_access',
            'default_enabled' => true,
            'implemented' => true,
        ],

        'geonames' => [
            'label' => 'GeoNames',
            'roles' => ['geographic_context'],
            'tier' => 'free',
            'credentials' => ['username' => 'Username (only for the web service)'],
            'credentials_optional' => true,
            'env_credentials' => ['username' => env('GEONAMES_USERNAME')],
            'coverage' => 'Cities and alternate names worldwide',
            'persistable' => true,
            'freshness' => 'Imported city file; web lookups cached',
            'independence_group' => 'geographic',
            'rate_limit' => ['description' => 'Free web service: 10,000 credits/day; local import preferred', 'daily_quota' => 1000, 'unit' => 'requests'],
            'capabilities' => ['city_normalization', 'alternate_names', 'arabic_names'],
            'docs_url' => 'https://www.geonames.org/export/',
            'notes' => 'Geographic context only; never a business source.',
            'default_enabled' => true,
            'implemented' => true,
        ],

        'openaddresses' => [
            'label' => 'OpenAddresses',
            'roles' => ['address_context'],
            'tier' => 'free',
            'credentials' => [],
            'coverage' => 'Address points where open data exists (sparse in the GCC)',
            'persistable' => true,
            'freshness' => 'Imported files',
            'independence_group' => 'address_data',
            'rate_limit' => ['description' => 'Local data only', 'daily_quota' => null, 'unit' => 'rows'],
            'capabilities' => ['address_normalization', 'address_context'],
            'docs_url' => 'https://openaddresses.io/',
            'notes' => 'Address context only; never raises a lead score.',
            'default_enabled' => true,
            'implemented' => true,
        ],

        // Future / premium sources. Listed so the architecture and UI are ready;
        // they are not implemented and can never be called.

        'google_places' => [
            'label' => 'Google Places', 'roles' => ['discovery', 'verification', 'enrichment'], 'tier' => 'paid',
            'credentials' => ['api_key' => 'API key'], 'coverage' => 'Global', 'persistable' => false,
            'freshness' => 'Live', 'independence_group' => 'google',
            'rate_limit' => ['description' => 'Pay per request', 'daily_quota' => 0, 'unit' => 'requests'],
            'capabilities' => ['ratings', 'reviews', 'opening_hours'], 'docs_url' => 'https://developers.google.com/maps/documentation/places/web-service',
            'notes' => 'Terms restrict storing most fields.', 'default_enabled' => false, 'implemented' => false,
        ],
        'brave_places' => [
            'label' => 'Brave Places', 'roles' => ['discovery', 'verification'], 'tier' => 'paid',
            'credentials' => ['api_key' => 'API key'], 'coverage' => 'Global', 'persistable' => false,
            'freshness' => 'Live', 'independence_group' => 'brave',
            'rate_limit' => ['description' => 'Pay per request', 'daily_quota' => 0, 'unit' => 'requests'],
            'capabilities' => ['web_presence'], 'docs_url' => 'https://brave.com/search/api/', 'default_enabled' => false, 'implemented' => false,
        ],
        'wathq' => [
            'label' => 'WATHQ (Saudi Business Registry)', 'roles' => ['verification'], 'tier' => 'paid',
            'credentials' => ['api_key' => 'API key'], 'coverage' => 'Saudi Arabia', 'persistable' => true,
            'freshness' => 'Live', 'independence_group' => 'government_registry',
            'rate_limit' => ['description' => 'Contracted quota', 'daily_quota' => 0, 'unit' => 'requests'],
            'capabilities' => ['commercial_registration', 'legal_name', 'branches'], 'docs_url' => 'https://developer.wathq.sa/', 'default_enabled' => false, 'implemented' => false,
        ],
        'saudi_moc' => [
            'label' => 'Saudi Ministry of Commerce', 'roles' => ['verification'], 'tier' => 'paid',
            'credentials' => ['api_key' => 'API key'], 'coverage' => 'Saudi Arabia', 'persistable' => true,
            'freshness' => 'Live', 'independence_group' => 'government_registry',
            'rate_limit' => ['description' => 'Contracted quota', 'daily_quota' => 0, 'unit' => 'requests'],
            'capabilities' => ['commercial_registration'], 'docs_url' => 'https://mc.gov.sa/', 'default_enabled' => false, 'implemented' => false,
        ],
        'saudi_national_address' => [
            'label' => 'Saudi National Address', 'roles' => ['address_context', 'verification'], 'tier' => 'paid',
            'credentials' => ['api_key' => 'API key'], 'coverage' => 'Saudi Arabia', 'persistable' => true,
            'freshness' => 'Live', 'independence_group' => 'national_address',
            'rate_limit' => ['description' => 'Contracted quota', 'daily_quota' => 0, 'unit' => 'requests'],
            'capabilities' => ['address_verification'], 'docs_url' => 'https://api.address.gov.sa/', 'default_enabled' => false, 'implemented' => false,
        ],
        'uae_ner' => [
            'label' => 'UAE National Economic Register', 'roles' => ['verification'], 'tier' => 'paid',
            'credentials' => ['api_key' => 'API key'], 'coverage' => 'United Arab Emirates', 'persistable' => true,
            'freshness' => 'Live', 'independence_group' => 'government_registry',
            'rate_limit' => ['description' => 'Contracted quota', 'daily_quota' => 0, 'unit' => 'requests'],
            'capabilities' => ['trade_license'], 'docs_url' => 'https://ner.economy.ae/', 'default_enabled' => false, 'implemented' => false,
        ],
        'dubai_det' => [
            'label' => 'Dubai DET', 'roles' => ['verification'], 'tier' => 'paid',
            'credentials' => ['api_key' => 'API key'], 'coverage' => 'Dubai', 'persistable' => true,
            'freshness' => 'Live', 'independence_group' => 'government_registry',
            'rate_limit' => ['description' => 'Contracted quota', 'daily_quota' => 0, 'unit' => 'requests'],
            'capabilities' => ['trade_license'], 'docs_url' => 'https://www.dubaidet.gov.ae/', 'default_enabled' => false, 'implemented' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Campaign Presets
    |--------------------------------------------------------------------------
    |
    | Discovery sources pre-selected for a campaign. Sources that are not
    | available (disabled, unconfigured or not implemented) are dropped. Brand
    | registry and website intelligence always run after discovery.
    |
    */

    'presets' => [
        'free_balanced' => [
            'label' => 'Free balanced',
            'description' => 'OpenStreetMap, Overture and Geoapify (when configured), plus brand registry and website intelligence.',
            'discovery' => ['openstreetmap', 'overture', 'geoapify'],
            'paid' => false,
        ],
        'free_precision' => [
            'label' => 'Free precision',
            'description' => 'OpenStreetMap and Overture, cross-checked against first-party AllThePlaces branches.',
            'discovery' => ['openstreetmap', 'overture', 'alltheplaces'],
            'paid' => false,
        ],
        'paid_enrichment' => [
            'label' => 'Paid enrichment (future)',
            'description' => 'Reserved for paid providers. Not available yet.',
            'discovery' => ['google_places', 'brave_places'],
            'paid' => true,
        ],
    ],

    'default_preset' => env('SOURCES_DEFAULT_PRESET', 'free_balanced'),

    /*
    |--------------------------------------------------------------------------
    | Overture Places
    |--------------------------------------------------------------------------
    |
    | Overture is used from bounded geographic extracts imported into the
    | source_places table, never from the global dataset. Imports are streamed
    | and processed in resumable chunks.
    |
    */

    'overture' => [
        'max_upload_mb' => (int) env('OVERTURE_MAX_UPLOAD_MB', 50),
        'max_remote_mb' => (int) env('OVERTURE_MAX_REMOTE_MB', 100),
        // Rows processed per job run before the import re-queues itself.
        'chunk_rows' => (int) env('OVERTURE_CHUNK_ROWS', 2000),
        'chunk_seconds' => (int) env('OVERTURE_CHUNK_SECONDS', 40),
        // Records below this existence confidence are treated as junk.
        'min_confidence' => (float) env('OVERTURE_MIN_CONFIDENCE', 0.35),
        'disk' => env('OVERTURE_DISK', 'local'),
        'directory' => 'imports/overture',
        // Optional advanced mode; never required.
        'duckdb' => [
            'enabled' => (bool) env('OVERTURE_DUCKDB_ENABLED', false),
            'binary' => env('OVERTURE_DUCKDB_BINARY', 'duckdb'),
            'release' => env('OVERTURE_RELEASE', '2025-09-24.0'),
            'max_rows' => 20000,
            'timeout' => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AllThePlaces
    |--------------------------------------------------------------------------
    */

    'alltheplaces' => [
        'latest_run_url' => env('ATP_LATEST_RUN_URL', 'https://data.alltheplaces.xyz/runs/latest.json'),
        'max_download_mb' => (int) env('ATP_MAX_DOWNLOAD_MB', 60),
        // Only features in these countries are kept (empty = supported campaign countries).
        'countries' => array_filter(explode(',', (string) env('ATP_COUNTRIES', ''))),
        'match_distance_meters' => 150,
    ],

    /*
    |--------------------------------------------------------------------------
    | Geoapify
    |--------------------------------------------------------------------------
    */

    'geoapify' => [
        'base_url' => env('GEOAPIFY_URL', 'https://api.geoapify.com'),
        'page_size' => 100,
        'max_results' => (int) env('GEOAPIFY_MAX_RESULTS', 500),
        // Places API: one credit per 20 places returned (minimum one per request).
        'places_per_credit' => 20,
        // BusinessCategory => Geoapify place categories.
        'categories' => [
            'restaurant' => ['catering.restaurant', 'catering.fast_food', 'catering.food_court'],
            'cafe' => ['catering.cafe', 'catering.ice_cream'],
            'bakery' => ['commercial.food_and_drink.bakery', 'commercial.food_and_drink.confectionery'],
            'hotel' => ['accommodation.hotel', 'accommodation.motel', 'accommodation.guest_house', 'accommodation.hostel', 'accommodation.apartment'],
            'clinic' => ['healthcare.clinic_or_praxis'],
            'dentist' => ['healthcare.dentist'],
            'pharmacy' => ['healthcare.pharmacy'],
            'beauty' => ['service.beauty', 'commercial.health_and_beauty'],
            'fitness' => ['sport.fitness'],
            'real_estate' => ['office.estate_agent'],
            'car_dealer' => ['commercial.vehicle'],
            'car_services' => ['service.vehicle'],
            'car_rental' => ['rental.car'],
            'education' => ['education.school', 'education.language_school', 'education.driving_school', 'education.college'],
            'kindergarten' => ['childcare.kindergarten'],
            'legal' => ['office.lawyer', 'office.notary'],
            'accounting' => ['office.accountant', 'office.tax_advisor'],
            'travel' => ['service.travel_agency', 'office.travel_agent'],
            'furniture' => ['commercial.furniture_and_interior'],
            'fashion' => ['commercial.clothing'],
            'jewelry' => ['commercial.jewelry', 'commercial.watches'],
            'electronics' => ['commercial.elektronics'],
            'grocery' => ['commercial.supermarket', 'commercial.convenience'],
            'florist' => ['commercial.florist', 'commercial.gift_and_souvenir'],
            'laundry' => ['service.cleaning'],
            'optician' => ['commercial.health_and_beauty.optician'],
            'veterinary' => ['pet.veterinary', 'pet.shop'],
            'events' => ['building.entertainment'],
            'contractors' => ['office.company'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Name Suggestion Index
    |--------------------------------------------------------------------------
    |
    | Only the brand categories relevant to lead discovery are downloaded, one
    | small file each, and only brands active in the countries below (or
    | worldwide) are kept.
    |
    */

    'nsi' => [
        'base_url' => env('NSI_BASE_URL', 'https://raw.githubusercontent.com/osmlab/name-suggestion-index/main/data'),
        'max_file_mb' => 15,
        'countries' => ['sa', 'ae', 'qa', 'kw', 'bh', 'om', 'eg', 'jo', 'lb'],
        'categories' => [
            'brands/amenity/fast_food', 'brands/amenity/restaurant', 'brands/amenity/cafe', 'brands/amenity/ice_cream',
            'brands/amenity/bank', 'brands/amenity/pharmacy', 'brands/amenity/clinic', 'brands/amenity/dentist',
            'brands/amenity/fuel', 'brands/amenity/car_rental', 'brands/amenity/kindergarten', 'brands/amenity/language_school',
            'brands/shop/supermarket', 'brands/shop/convenience', 'brands/shop/bakery', 'brands/shop/coffee',
            'brands/shop/clothes', 'brands/shop/shoes', 'brands/shop/electronics', 'brands/shop/mobile_phone',
            'brands/shop/optician', 'brands/shop/beauty', 'brands/shop/cosmetics', 'brands/shop/perfumery',
            'brands/shop/hairdresser', 'brands/shop/jewelry', 'brands/shop/furniture', 'brands/shop/car',
            'brands/shop/car_repair', 'brands/shop/tyres', 'brands/shop/pastry', 'brands/shop/chocolate',
            'brands/shop/department_store', 'brands/shop/hardware', 'brands/shop/doityourself', 'brands/shop/toys',
            'brands/shop/sports', 'brands/shop/pet', 'brands/shop/laundry', 'brands/shop/dry_cleaning',
            'brands/tourism/hotel', 'brands/leisure/fitness_centre', 'brands/healthcare/laboratory',
            'brands/office/estate_agent', 'brands/office/telecommunication', 'brands/office/insurance',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Wikidata
    |--------------------------------------------------------------------------
    */

    'wikidata' => [
        'entity_url' => env('WIKIDATA_ENTITY_URL', 'https://www.wikidata.org/wiki/Special:EntityData/%s.json'),
        'cache_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | GeoNames and OpenAddresses
    |--------------------------------------------------------------------------
    */

    'geonames' => [
        'api_url' => env('GEONAMES_API_URL', 'https://secure.geonames.org'),
        'cities_url' => env('GEONAMES_CITIES_URL', 'https://download.geonames.org/export/dump/cities15000.zip'),
        'countries' => ['SA', 'AE', 'QA', 'KW', 'BH', 'OM'],
    ],

    'openaddresses' => [
        'match_distance_meters' => 40,
    ],

];
