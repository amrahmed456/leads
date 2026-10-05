# Leads — Business Discovery & Digital Presence Audit

A personal lead-generation tool for finding real businesses in Gulf markets
(Saudi Arabia, UAE, Qatar, Kuwait, Bahrain, Oman).

- **Phase 1, discovery:** create a search campaign, query a discovery provider
  (OpenStreetMap / Overpass, free, no API key), normalize and deduplicate the
  results, store them in MySQL and browse them.
- **Phase 2, intelligence:** audit each business's homepage with deterministic
  rules, detect sales opportunities (website, booking, ordering, e-commerce, ...)
  and compute an explainable lead score. See
  [Digital presence, opportunities and lead scores](#digital-presence-opportunities-and-lead-scores).

Not included yet: AI, outreach, CRM, Google Places.

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

## Digital presence, opportunities and lead scores

When a discovery campaign completes, `QueueCampaignAudits` runs:

- **Businesses without a website** get a *not applicable* audit record. They are
  evaluated straight away, which produces a **Website development** opportunity
  and a lead score.
- **Businesses with a website** get a **homepage audit** queued. Audits are
  spread out (`AUDIT_PER_MINUTE`, default 20) and rate-limited across workers.

You can also trigger this from the **Re-audit website** / **Re-evaluate** button
on a business page, or from the command line:

```bash
php artisan audits:queue --campaign=2        # one campaign
php artisan audits:queue --business=44       # specific businesses (repeatable)
php artisan audits:queue --all               # backfill everything
php artisan audits:queue --all --sync        # run now, in this process, paced
php artisan audits:queue --business=44 --force   # ignore freshness
```

### Website audit (`app/Auditing`)

```
WebsiteAuditScheduler   freshness + duplicate prevention + batching → AuditBusinessWebsite job
  └─ WebsiteAuditService
       1. WebsiteFetcher   homepage only, safe and bounded (see below)
       2. HtmlDocument     parse once (DOMDocument); never stored
       3. WebsiteAnalyzer  deterministic detectors → signals with confidence + evidence
       4. AuditScorer      explainable 0–100 technical / digital presence score
  └─ BusinessEvaluator  (app/Intelligence) → opportunities + lead score
```

**Fetching rules**

- Only the homepage: one page, at most 5 redirects, no crawling. Links on the
  page are inspected but never fetched.
- Connect timeout 8 s, response timeout 15 s, 45 s budget per audit. Only the
  first 1.5 MB is analyzed, and downloads stop at 6 MB.
- An honest User-Agent (`LeadsAudit/1.0`) and `Accept-Language: ar,en`.
- **SSRF protection.** Website URLs come from public map data, so every host
  (including each redirect target) must resolve to public IP addresses only.
  The connection is then pinned to the checked address.
- An invalid TLS certificate is tolerated so the page can still be analyzed, and
  is recorded as *Invalid SSL certificate*. A site unreachable over HTTPS is
  tried once over HTTP.
- `401/403` (blocked) and `404` are permanent. Timeouts, connection errors,
  `429` (honoring `Retry-After`) and `5xx` are retried twice, after 5 and 30
  minutes.

**Signals** (all deterministic, English and Arabic). Each signal stores a
confidence (`none/low/medium/high`) and evidence. A signal counts as present
from *medium* confidence; a word in the text alone is only a *low* hint.

| Signal | Strong evidence | Medium evidence |
|---|---|---|
| Phone | `tel:` links | E.164-valid numbers in the text (years and dates excluded) |
| E-mail | `mailto:` links | Addresses in the text; Cloudflare-protected addresses |
| WhatsApp | `wa.me`, `api.whatsapp.com`, `whatsapp:` links | Chat widgets (Joinchat, Click-to-Chat, ...) |
| Contact form | Form with a message field + name/e-mail/phone; CF7, WPForms, Gravity, Elementor forms | Forms collecting contact details; Google Forms, Typeform, Jotform... |
| Booking | Calendly, Fresha, Vezeeta, Okadoc, OpenTable, Eat App, SevenRooms...; booking plugins | "Book now" / "احجز موعد" buttons, `/booking` pages, date pickers |
| Online ordering | ChatFood, Zyda, Ordable, GloriaFood...; `order.` sites | "Order now" / "اطلب الآن" buttons, `/order` pages |
| Delivery apps | Links to Talabat, HungerStation, Jahez, Mrsool, Careem, Deliveroo... (reported separately; not own ordering) | |
| E-commerce / cart | Shopify, Salla, Zid, WooCommerce, Magento...; add-to-cart buttons/forms | Cart / checkout links |
| Mobile | `width=device-width` viewport | Bootstrap/Tailwind, media queries, site builders |
| Own mobile app | App Store / Google Play links (delivery apps excluded) | |
| Languages | Arabic vs Latin letter counts, `<html lang>`, `hreflang`, language switchers | |
| Technology | WordPress, WooCommerce, Shopify, Wix, Squarespace, Webflow, Salla, Zid, Magento, Joomla, Drupal, Laravel, Next.js, analytics pixels (from markup, response headers and cookie names only) | |
| Outdated | Flash, placeholder / "coming soon" pages | Copyright ≥ 3 years old, frames |

**Audit score.** The score is the share of applicable points earned, with
weights in `config/auditing.php`. Every check is stored with a label, so the UI
lists *positive* and *negative* signals. Booking, ordering and e-commerce only
count for categories that need them. Visual design is **not** judged.

**Freshness.** A completed audit is reused for 30 days. A failed one is retried
after 24 h. Branches sharing a website reuse a fresh audit of the same URL (same
category) without fetching. **Re-audit** always fetches. Only one audit per
business can be queued or running at a time; this is enforced under a database
row lock.

### Opportunities (`app/Intelligence/Opportunities`)

One rule class per opportunity. Rules only use facts the audit actually
established: a blocked or failed audit never produces "missing feature"
opportunities. Category needs and priorities live in `config/intelligence.php`.

| Opportunity | Rule |
|---|---|
| Website development | No website. High if the business is contactable (phone / e-mail / WhatsApp / social), otherwise medium |
| Website redesign | Website down (DNS, connection, timeout, 404, 5xx) or a placeholder page → high. Otherwise outdated content, a low audit score (< 40), or ≥ 2 issues (no HTTPS, no viewport) |
| Mobile improvement | No mobile viewport |
| Online booking | The category needs booking (clinics, dentists, salons, hotels...) and none was found. Fast food is excluded |
| Online ordering | The category needs ordering (restaurants, cafés, bakeries) and none was found. High when only delivery apps are used; low when the business has its own app |
| E-commerce | A retail category with no cart / checkout |
| WhatsApp integration | No WhatsApp on the site although the business has a phone number |
| Multilingual website | Only Arabic or only English, with no link to the other version |
| Custom business system, Mobile app | Reserved; no deterministic rule yet |

### Lead score (`app/Intelligence/Scoring/LeadScoringService`)

Rule-based, 0–100, with weights in `config/intelligence.php` (`lead_score`,
versioned). Every contributing rule is stored with its points and shown in the UI.

| Component | Points |
|---|---|
| Website status | no website +40 · down/broken +30 · weak (< 50) +15 · average (< 80) +5 · strong 0 |
| No website, but the category needs booking/ordering/e-commerce | +8 (high need) / +4 (medium) |
| Each other opportunity | high +10 · medium +6 · low +3, capped at 25 in total |
| Reachability | phone +8 · e-mail +4 · WhatsApp +3 · social profiles +5 · none at all −15 |
| Profile | verified location +2 · high-value category +10 · other listed category +5 |

Grades: **hot** ≥ 70, **warm** ≥ 45, otherwise **cold**.

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

Website audits run in `App\Jobs\AuditBusinessWebsite`:

- Up to 3 fetch attempts (temporary failures retried after 5 and 30 minutes,
  or after `Retry-After`). Permanent failures are recorded at once.
- Limited by time (`retryUntil`: 24 h), so rate-limit releases never exhaust it.
- Rate-limited across all workers (`AUDIT_PER_MINUTE`).
- Idempotent: an audit that already finished is skipped. A failing website only
  fails its own audit, never the campaign.
- Job timeout 120 s (below `retry_after`).

Redis is optional; `QUEUE_CONNECTION=database` is the supported default.

## Scheduler

| Task | Frequency | Purpose |
|---|---|---|
| `queue:work --stop-when-empty --max-time=50` | every minute | Processes the database queue without a daemon (when `QUEUE_SCHEDULED_WORKER=true`) |
| `campaigns:fail-stale` | every 15 min | Fails campaigns stuck in *running* > 60 min (worker died) |
| `audits:fail-stale` | every 15 min | Fails audits *running* > 30 min or *queued* > 48 h |

Locally, either run `php artisan queue:work` (and set `QUEUE_SCHEDULED_WORKER=false`)
or run `php artisan schedule:work`.

## Deploying on cPanel shared hosting

No Redis, Supervisor, Docker, Node server or daemon is required.

1. Build assets locally (`npm run build`) and upload the project, including
   `public/build`. Node is not needed on the server.
2. Point the domain's document root to `public/` (or move `public/*` into
   `public_html` and adjust the paths in `index.php`).
3. On the server: `composer install --no-dev --optimize-autoloader`,
   create `.env` (`APP_ENV=production`, `APP_DEBUG=false`, MySQL credentials,
   `QUEUE_CONNECTION=database`, `QUEUE_SCHEDULED_WORKER=true`), then:

   ```bash
   php artisan key:generate
   php artisan migrate --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   php artisan app:create-user
   ```

4. Add **one** cron job in cPanel → *Cron Jobs* → *Once Per Minute*
   (`* * * * *`). Use the PHP CLI path your host shows (often
   `/usr/local/bin/php` or `/opt/cpanel/ea-php83/root/usr/bin/php`):

   ```
   cd /home/USERNAME/leads && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
   ```

   Every minute this processes the queued discovery and audit jobs (exiting when
   the queue is empty) and runs the stale-job cleanups. Jobs wait at most about
   a minute to start.

5. `storage/` and `bootstrap/cache/` must be writable by the PHP user.

Notes: the host must allow outbound HTTPS (for OpenStreetMap and the audited
websites) and `proc_open` (used by the scheduler). `HTTP_CA_BUNDLE` is normally
unnecessary on Linux hosts.

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
| `AUDIT_QUEUE` | `default` | Queue name for audit jobs |
| `AUDIT_PER_MINUTE` | `20` | Website audits per minute across all workers |
| `AUDIT_CONNECT_TIMEOUT` / `AUDIT_TIMEOUT` | `8` / `15` | Seconds per request |
| `AUDIT_FRESH_DAYS` | `30` | Reuse a successful audit this long |
| `AUDIT_RETRY_FAILED_AFTER_HOURS` | `24` | Wait before retrying a failed audit |
| `AUDIT_USER_AGENT` | `Mozilla/5.0 (compatible; LeadsAudit/1.0; ...)` | Identifies the auditor to websites |
| `LOG_AUDITS_STACK` | `audits_file` | Channels that receive audit logs (`storage/logs/audits-*.log`) |
| `QUEUE_SCHEDULED_WORKER` | `true` | Run a short-lived queue worker from the scheduler (cPanel) |
| `QUEUE_SCHEDULED_WORKER_MAX_TIME` | `50` | Seconds that worker keeps taking new jobs |

No API keys are needed.

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
  Auditing/
    Contracts/HostResolver, DnsHostResolver, UrlGuard, WebsiteFetcher, HtmlDocument, KeywordMatcher
    Detectors/        Contact, Booking, Ordering, Ecommerce, Mobile, MobileApp, Maintenance, Technology, Language
    Data/             FetchResult, WebsiteAnalysis, DetectedSignal, DetectedTechnology, LanguageProfile, AuditScore
    WebsiteAnalyzer, AuditScorer, WebsiteAuditService, WebsiteAuditScheduler
  Intelligence/
    Opportunities/    OpportunityDetector, Rules/* (one class per opportunity)
    Scoring/          LeadScoringService, LeadScoreResult
    BusinessEvaluator, CategoryNeeds
  Support/Normalization/  Phone, URL, e-mail, social link and text normalizers
  Enums/              BusinessCategory, CampaignStatus, Country, AuditStatus, AuditFailure, Confidence,
                      WebsiteSignal, OpportunityType, OpportunityPriority, LeadGrade
  Jobs/               RunCampaignDiscovery, QueueCampaignAudits, AuditBusinessWebsite
  Livewire/           Dashboard, Campaigns/{Index,Create,Show}, Businesses/{Index,Show}
  Models/             SearchCampaign, Business, BusinessSource, WebsiteAudit, Opportunity, LeadScore, User
  Policies/           SearchCampaignPolicy, BusinessPolicy
  Console/Commands/   app:create-user, campaigns:discover, campaigns:fail-stale, audits:queue, audits:fail-stale
config/discovery.php, config/auditing.php, config/intelligence.php
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

**Website audits**

- Only the server-rendered HTML of the homepage is analyzed. JavaScript is not
  executed, external stylesheets are not downloaded, and inner pages (e.g. a
  separate /contact page) are not visited. A business may therefore have a
  phone, booking or ordering on another page or in client-rendered content.
- Signals are deterministic heuristics with confidence levels, not proof:
  keyword matching can miss unusual wording or flag look-alikes.
- The audit score measures technical and digital presence signals, not visual
  design or real mobile usability. A viewport tag is a signal, not a Lighthouse
  result.
- Sites behind bot protection (HTTP 403) cannot be analyzed; this is reported,
  never treated as missing features.
- robots.txt is not consulted: only a single homepage request is made per
  audit, similar to a link preview.
- Audits made before a detection rule changes keep their old results until
  they are refreshed (after 30 days, or via **Re-audit**).

OpenStreetMap data © OpenStreetMap contributors, available under the
[Open Database License](https://www.openstreetmap.org/copyright).
