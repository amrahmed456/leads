<?php

namespace App\Discovery\Providers\Geoapify;

use App\Discovery\Contracts\BusinessDiscoveryProviderInterface;
use App\Discovery\Data\DiscoveryQuery;
use App\Discovery\Data\DiscoveryResult;
use App\Discovery\Exceptions\DiscoveryException;
use App\Sources\QuotaExceededException;
use App\Sources\QuotaManager;
use App\Sources\SecretScrubber;
use App\Sources\SourceRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Discovers businesses through the Geoapify Places API.
 *
 * Geoapify serves OpenStreetMap data, so it belongs to the "osm_family"
 * independence group and never counts as independent confirmation of an
 * OSM record; its results carry their OSM ID and merge with OSM records.
 *
 * Credits are reserved before each page and the daily budget is never
 * exceeded. A quota response stops the source for the day, without retry.
 *
 * @see https://apidocs.geoapify.com/docs/places/
 */
final class GeoapifyProvider implements BusinessDiscoveryProviderInterface
{
    public const KEY = 'geoapify';

    /**
     * @param  array<string, list<string>>  $categories  BusinessCategory value => Geoapify categories
     */
    public function __construct(
        private readonly GeoapifyFeatureMapper $mapper,
        private readonly SourceRegistry $sources,
        private readonly QuotaManager $quotas,
        private readonly string $baseUrl,
        private readonly array $categories,
        private readonly int $pageSize,
        private readonly int $maxResults,
        private readonly int $placesPerCredit,
        private readonly string $userAgent,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Geoapify Places';
    }

    public function discover(DiscoveryQuery $query): DiscoveryResult
    {
        $apiKey = $this->sources->credential(self::KEY, 'api_key')
            ?? throw DiscoveryException::permanent('Geoapify needs an API key (Settings → Data Sources).');

        $categories = $this->categories[$query->category->value] ?? [];

        if ($categories === []) {
            throw DiscoveryException::permanent("Geoapify has no category mapping for [{$query->category->value}].");
        }

        $businesses = [];
        $skipped = 0;
        $offset = 0;
        $truncated = false;

        while (true) {
            $limit = min($this->pageSize, $this->maxResults - $offset);
            $reserved = (int) ceil($limit / $this->placesPerCredit);

            try {
                $this->quotas->consume(self::KEY, $reserved);
            } catch (QuotaExceededException $e) {
                // Keep what earlier pages found; only fail when nothing was fetched.
                if ($offset > 0) {
                    $truncated = true;

                    break;
                }

                throw DiscoveryException::permanent($e->getMessage(), $e);
            }

            $features = $this->fetchPage($apiKey, $categories, $query, $limit, $offset);

            // Charge what the page actually cost.
            $this->quotas->adjust(self::KEY, max(1, (int) ceil(count($features) / $this->placesPerCredit)) - $reserved);

            foreach ($features as $feature) {
                $business = is_array($feature) ? $this->mapper->map($feature, $query) : null;

                if ($business === null) {
                    $skipped++;

                    continue;
                }

                $businesses[] = $business;
            }

            $offset += count($features);

            if (count($features) < $limit) {
                break;
            }

            if ($offset >= $this->maxResults) {
                $truncated = true;

                break;
            }
        }

        return new DiscoveryResult($businesses, $skipped, $truncated);
    }

    /**
     * @param  list<string>  $categories
     * @return list<mixed>
     */
    private function fetchPage(string $apiKey, array $categories, DiscoveryQuery $query, int $limit, int $offset): array
    {
        $center = sprintf('%.6F,%.6F', $query->longitude, $query->latitude);

        try {
            $response = Http::withUserAgent($this->userAgent)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->get(rtrim($this->baseUrl, '/').'/v2/places', [
                    'categories' => implode(',', $categories),
                    'filter' => "circle:{$center},{$query->radiusMeters}",
                    'bias' => "proximity:{$center}",
                    'limit' => $limit,
                    'offset' => $offset,
                    'lang' => 'en',
                    'apiKey' => $apiKey,
                ]);
        } catch (ConnectionException $e) {
            $this->quotas->recordError(self::KEY);

            throw DiscoveryException::transient($this->scrub('Could not reach Geoapify: '.$e->getMessage()), $e);
        }

        $this->guard($response);

        $features = $response->json('features');

        if (! is_array($features) || ! array_is_list($features)) {
            throw DiscoveryException::transient('Geoapify returned a malformed response.');
        }

        return $features;
    }

    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $this->quotas->recordError(self::KEY);

        if ($response->status() === 429 || $response->status() === 402) {
            // Do not keep retrying a used-up quota: stop for the rest of the day.
            $this->quotas->markExhausted(self::KEY);

            throw DiscoveryException::permanent('Geoapify quota or rate limit reached (HTTP '.$response->status().'). Paused until tomorrow.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw DiscoveryException::permanent('Geoapify rejected the API key (HTTP '.$response->status().').');
        }

        if ($response->serverError()) {
            throw DiscoveryException::transient("Geoapify is unavailable (HTTP {$response->status()}).");
        }

        throw DiscoveryException::permanent($this->scrub("Geoapify rejected the request (HTTP {$response->status()}): ".mb_substr(strip_tags($response->body()), 0, 200)));
    }

    private function scrub(string $message): string
    {
        return SecretScrubber::scrub($message, $this->sources->secrets(self::KEY));
    }
}
