<?php

namespace App\Discovery\Providers\OpenStreetMap;

use App\Discovery\Contracts\BusinessDiscoveryProviderInterface;
use App\Discovery\Data\DiscoveryQuery;
use App\Discovery\Data\DiscoveryResult;
use App\Discovery\Exceptions\DiscoveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Discovers businesses from OpenStreetMap through the public Overpass API.
 *
 * Free to use and requires no API key, subject to the Overpass usage policy
 * (reasonable request volume and an identifying User-Agent).
 *
 * @see https://wiki.openstreetmap.org/wiki/Overpass_API
 */
final class OpenStreetMapProvider implements BusinessDiscoveryProviderInterface
{
    public const KEY = 'openstreetmap';

    public function __construct(
        private readonly OverpassQueryBuilder $queryBuilder,
        private readonly OsmElementMapper $mapper,
        private readonly string $endpoint,
        private readonly int $queryTimeout,
        private readonly int $maxResults,
        private readonly string $userAgent,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'OpenStreetMap';
    }

    public function discover(DiscoveryQuery $query): DiscoveryResult
    {
        $elements = $this->fetchElements(
            $this->queryBuilder->build($query, $this->queryTimeout, $this->maxResults),
        );

        $businesses = [];
        $skipped = 0;

        foreach ($elements as $element) {
            $business = is_array($element) ? $this->mapper->map($element, $query) : null;

            if ($business === null) {
                $skipped++;

                continue;
            }

            $businesses[] = $business;
        }

        return new DiscoveryResult($businesses, $skipped, truncated: count($elements) >= $this->maxResults);
    }

    /**
     * @return list<mixed>
     *
     * @throws DiscoveryException
     */
    private function fetchElements(string $overpassQuery): array
    {
        try {
            $response = Http::withUserAgent($this->userAgent)
                ->acceptJson()
                ->connectTimeout(15)
                ->timeout($this->queryTimeout + 30)
                ->retry([3000, 10000], when: fn (Throwable $e): bool => $this->isTransient($e), throw: false)
                ->asForm()
                ->post($this->endpoint, ['data' => $overpassQuery]);
        } catch (ConnectionException $e) {
            throw DiscoveryException::transient('Could not reach the Overpass API: '.$e->getMessage(), $e);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw DiscoveryException::transient(
                "The Overpass API is busy or unavailable (HTTP {$response->status()}). Try again later or use a smaller radius."
            );
        }

        if ($response->failed()) {
            throw DiscoveryException::permanent(
                "The Overpass API rejected the query (HTTP {$response->status()}): {$this->excerpt($response)}"
            );
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['elements']) || ! is_array($data['elements']) || ! array_is_list($data['elements'])) {
            throw DiscoveryException::transient('The Overpass API returned a malformed response: '.$this->excerpt($response));
        }

        // Overpass reports runtime failures with HTTP 200 and a "remark"; the elements are then incomplete.
        $remark = is_string($data['remark'] ?? null) ? $data['remark'] : '';

        if (preg_match('/out of memory/i', $remark)) {
            throw DiscoveryException::permanent('The Overpass query is too large: '.Str::limit($remark, 300).' Use a smaller radius.');
        }

        if (preg_match('/runtime error|timed out/i', $remark)) {
            throw DiscoveryException::transient('The Overpass query did not complete: '.Str::limit($remark, 300));
        }

        return $data['elements'];
    }

    private function isTransient(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError()));
    }

    private function excerpt(Response $response): string
    {
        return Str::limit(trim(strip_tags($response->body())), 300);
    }
}
