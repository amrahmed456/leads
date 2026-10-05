# Leads — Business Discovery (Phase 1)

A personal lead-generation tool for finding real businesses in Gulf markets
(Saudi Arabia, UAE, Qatar, Kuwait, Bahrain, Oman).

Phase 1 covers **business discovery only**: create a search campaign, query a
discovery provider, normalize and deduplicate the results, store them in MySQL
and browse them in a dashboard. The only provider in Phase 1 is
**OpenStreetMap** (Overpass API), which is free and needs no API key.

Not included yet: website auditing, AI, lead scoring, outreach and CRM.

---

## Requirements

| Tool     | Version                                      |
|----------|----------------------------------------------|
| PHP      | 8.3+ with `pdo_mysql`, `mbstring`, `intl`, `curl` |
| Composer | 2.x                                          |
| MySQL    | 8.x                                          |
| Node.js  | 20+ (only for building CSS)                  |
| Redis    | optional, see [Queues](#queues)              |

Stack: Laravel 13, Livewire 4, Blade, Tailwind CSS 4.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create two MySQL databases, one for the app and one for the test suite:

```sql
CREATE DATABASE leads CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE leads_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Set the `DB_*` values in `.env`, then run the migrations and build the assets:

```bash
php artisan migrate
npm install
npm run build
```

Create your sign-in account. There is no public registration:

```bash
php artisan app:create-user
```

> Local development only: `php artisan db:seed` creates `dev@example.com` with the
> factory password (see `database/seeders/DatabaseSeeder.php`). It does nothing
> outside `APP_ENV=local`.

### Windows / Laragon: HTTPS certificate errors

If discovery fails with `cURL error 60` or `cURL error 77`, PHP cannot find its
CA certificate bundle (`curl.cainfo` in `php.ini` points to a missing file).
Either fix `curl.cainfo` in `php.ini`, or point the app to a bundle in `.env`:

```dotenv
HTTP_CA_BUNDLE="C:/laragon/etc/ssl/cacert.pem"
```

Certificate verification always stays on.

## Running locally

Run these in three terminals:

```bash
php artisan serve            # http://127.0.0.1:8000
php artisan queue:work       # runs discovery jobs (required)
npm run dev                  # optional: rebuild CSS on change
```

Optional, for the scheduled maintenance task:

```bash
php artisan schedule:work
```

## Using it

### Create a campaign

1. **Campaigns → New campaign**.
2. Choose a **category** (e.g. *Restaurants & fast food*).
3. Optionally add **keywords** in any language, e.g. `مطعم, restaurant, cafe`.
   Keywords also include businesses of *any* type whose name contains one of them.
4. Choose a **country** and type a **city or area** (`Riyadh`, `Al Olaya, Riyadh`, `Dubai Marina`).
5. Set the **radius** (0.5–50 km).
6. Optionally enter a **custom centre point** (latitude/longitude) instead of the city centre.
7. Click **Create & start discovery**.

The campaign page polls while discovery is queued or running and shows the results
when it finishes, usually within a few seconds to a minute.

### Run discovery

- From the UI: creating a campaign queues discovery. **Run discovery again** re-runs it.
- From the CLI:

  ```bash
  php artisan campaigns:discover {id}          # queue it
  php artisan campaigns:discover {id} --sync   # run now in this process
  ```

Re-running a campaign refreshes known businesses. It never creates duplicates.

### Browse businesses

**Businesses** supports full-text search (name, alternate name, phone in local
or international format, website, e-mail, address). You can filter by campaign,
provider, city, category, website present/missing and phone present/missing, and
sort by newest, name, rating or review count. All filters live in the URL, so
filtered views can be bookmarked.

## How discovery works

```
Livewire page / artisan command
  └─ RunCampaignDiscovery (queued job: retryable, unique per campaign)
       └─ CampaignDiscoveryService
            1. GeocoderInterface → NominatimGeocoder   city → lat/lon (cached 30 days),
                                                       skipped if coordinates were given
            2. DiscoveryProviderRegistry → BusinessDiscoveryProviderInterface
                                          → OpenStreetMapProvider (Overpass API)
                                          → DiscoveryResult of DiscoveredBusiness DTOs
            3. BusinessNormalizer          clean/validate every record
            4. BusinessRecorder            deduplicate and store
            5. attach businesses to the campaign; record stats on the campaign
```

- **Provider abstraction.** `app/Discovery/Contracts/BusinessDiscoveryProviderInterface.php`
  has `key()`, `label()` and `discover(DiscoveryQuery): DiscoveryResult`. Providers
  receive a provider-independent `DiscoveryQuery` and return provider-independent
  `DiscoveredBusiness` objects. The database never sees the external API's response shape.
- **Adding a provider** (e.g. Google Places): implement the interface, then register
  it in `config/discovery.php` under `providers`. It then appears in the campaign form.
- **OpenStreetMap query.** Each category maps to OSM tags (`OsmTagMap`). The
  Overpass QL selects nodes, ways and relations within the radius. Keywords add a
  case-insensitive name search (`name`, `name:en`, `name:ar`, `brand`) over
  business-like elements. Ways and relations use their centre point.

### Data quality

| Concern | How it is handled |
|---|---|
| Provider ID uniqueness | Unique index on `business_sources (provider, provider_id)`; concurrent inserts are retried as updates. |
| Duplicates within a provider | A new record merges into an existing business with the same normalized name within 75 m (OSM often maps one shop as both a point and a building). |
| Overlapping campaigns | Businesses are shared through the `business_search_campaign` pivot, never copied. |
| Same business, several providers | One `businesses` row can have many `business_sources` rows. |
| Missing fields | Phone, website, e-mail, rating, reviews, address and coordinates are all nullable. A missing value never erases a known one. |
| Conflicting values | The source that created a business refreshes it. Merged sources only fill gaps. |
| Phone numbers | Normalized to E.164 using the country (`050 123 4567` → `+966501234567`). Handles Arabic-Indic digits, `00` prefixes, extensions and multi-number values. |
| URLs | Scheme added, host lowercased, tracking parameters and fragments removed, non-web schemes rejected. |
| "Websites" that are social profiles | Instagram, Facebook and similar URLs are moved to `social_links`, so "no website" stays accurate. |
| Names | Matching ignores case, punctuation, accents, Arabic diacritics and letter variants (أ/إ/ا, ة/ه, ى/ي). |
| Malformed responses | Invalid JSON, a missing `elements` list, Overpass runtime-error remarks and malformed elements are detected. Bad elements are skipped and counted. |
| Provider failures | Logged to `storage/logs/discovery-*.log` and shown on the campaign page. |

## Queues

Discovery always runs in a queued job (`App\Jobs\RunCampaignDiscovery`):

- Up to 3 attempts. Transient failures (timeouts, HTTP 429/5xx, connection errors)
  are retried after 60 s and then 300 s. Permanent failures (unknown location,
  rejected query) fail at once.
- Campaign status moves through `pending → running → completed | failed`.
- `ShouldBeUnique`: the same campaign cannot be queued twice at once.
- Job timeout is 300 s, so `retry_after` defaults to 600 s.

The default `QUEUE_CONNECTION=database` needs nothing extra. To use **Redis**,
install the `phpredis` extension (or `composer require predis/predis` and set
`REDIS_CLIENT=predis`), then set:

```dotenv
QUEUE_CONNECTION=redis
REDIS_QUEUE_RETRY_AFTER=600
```

To keep discovery on its own queue, set `DISCOVERY_QUEUE=discovery` and run
`php artisan queue:work --queue=discovery,default`.

## Scheduler

One scheduled task, `campaigns:fail-stale` (every 15 minutes), marks campaigns
stuck in *running* for over 60 minutes as *failed*, e.g. after a worker crash, so
they can be started again. In production, add the standard cron entry:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `DB_*` | — | MySQL connection |
| `QUEUE_CONNECTION` | `database` | `database` or `redis` |
| `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` | `600` | Must exceed the 300 s job timeout |
| `DISCOVERY_DEFAULT_PROVIDER` | `openstreetmap` | Pre-selected provider |
| `DISCOVERY_QUEUE` | `default` | Queue name for discovery jobs |
| `DISCOVERY_MAX_RADIUS_KM` | `50` | Largest allowed search radius |
| `DISCOVERY_DEDUPE_DISTANCE_METERS` | `75` | Same-name merge distance |
| `DISCOVERY_USER_AGENT` | `LeadsDiscovery/1.0 (...)` | Identifies the app to OSM services (required by their policies) |
| `DISCOVERY_CONTACT_EMAIL` | empty | Optional contact sent to Nominatim/Overpass |
| `OSM_ENABLED` | `true` | Enable the OpenStreetMap provider |
| `OSM_OVERPASS_URL` | `https://overpass-api.de/api/interpreter` | Overpass endpoint (a mirror can be used) |
| `OSM_OVERPASS_TIMEOUT` | `120` | Overpass server-side query timeout (s) |
| `OSM_MAX_RESULTS` | `3000` | Result cap per run; hitting it marks the run as truncated |
| `OSM_NOMINATIM_URL` | `https://nominatim.openstreetmap.org` | Geocoder endpoint |
| `GEOCODER_CACHE_DAYS` | `30` | Geocoding cache lifetime |
| `HTTP_CA_BUNDLE` | empty | CA bundle path if PHP's `curl.cainfo` is broken |
| `LOG_DISCOVERY_STACK` | `discovery_file` | Channels that receive discovery logs |

No API keys are needed in Phase 1.

## Tests

```bash
php artisan test
vendor/bin/pint --test
```

The suite runs against the `leads_testing` MySQL database, so unique indexes and
JSON columns behave as in production. All HTTP calls are faked: tests never
reach external services.

## Project layout

```
app/
  Discovery/
    Contracts/        BusinessDiscoveryProviderInterface, GeocoderInterface
    Data/             DiscoveryQuery, DiscoveredBusiness, DiscoveryResult, ...
    Providers/OpenStreetMap/  OpenStreetMapProvider, OverpassQueryBuilder, OsmElementMapper, OsmTagMap
    Geocoding/        NominatimGeocoder
    CampaignDiscoveryService, BusinessNormalizer, BusinessRecorder, DiscoveryProviderRegistry
  Support/Normalization/  Phone, URL, e-mail, social link and text normalizers
  Enums/              BusinessCategory, CampaignStatus, Country
  Jobs/               RunCampaignDiscovery
  Livewire/           Dashboard, Campaigns/{Index,Create,Show}, Businesses/{Index,Show}
  Models/             SearchCampaign, Business, BusinessSource, User
  Policies/           SearchCampaignPolicy, BusinessPolicy
  Console/Commands/   app:create-user, campaigns:discover, campaigns:fail-stale
config/discovery.php
```

## Limitations

- **OpenStreetMap coverage** depends on community mapping. Many Gulf businesses
  are missing or lack phone and website details, and OSM has **no ratings or
  reviews**: those columns stay empty until a provider that has them is added.
- Matching within a provider is conservative: same normalized name within 75 m.
  Branches of a chain closer than that would merge. Duplicate mappings further
  apart, or under different names (e.g. Arabic vs English only), stay separate.
  Phone numbers are not used for matching because chains share unified 920/800
  numbers.
- A business's `city` is the campaign's searched city. With large radii it can
  include neighbouring towns.
- Very large radii in dense cities can hit `OSM_MAX_RESULTS` or the Overpass
  timeout. The campaign then shows a warning or a failure suggesting a smaller radius.
- On Windows, PHP has no `pcntl`, so the worker cannot enforce the job timeout.
  `campaigns:fail-stale` covers stuck runs.
- The public Overpass and Nominatim instances are shared, rate-limited services.
  Keep usage moderate, or point the URLs at your own instance.

OpenStreetMap data © OpenStreetMap contributors, available under the
[Open Database License](https://www.openstreetmap.org/copyright).
