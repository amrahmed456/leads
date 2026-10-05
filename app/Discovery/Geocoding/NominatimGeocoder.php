<?php

namespace App\Discovery\Geocoding;

use App\Discovery\Contracts\GeocoderInterface;
use App\Discovery\Data\GeocodedLocation;
use App\Discovery\Exceptions\DiscoveryException;
use App\Enums\Country;
use App\Support\Normalization\Text;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Geocodes place names with OpenStreetMap's Nominatim service.
 *
 * The public instance allows at most one request per second and requires
 * results to be cached; one request per uncached campaign location is well
 * within that policy.
 *
 * @see https://operations.osmfoundation.org/policies/nominatim/
 */
final class NominatimGeocoder implements GeocoderInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly ?string $contactEmail,
        private readonly int $cacheDays,
    ) {}

    public function geocode(string $place, Country $country): ?GeocodedLocation
    {
        $place = trim($place);

        if ($place === '') {
            return null;
        }

        $cacheKey = 'geocoder:nominatim:'.sha1(mb_strtolower($place).'|'.$country->value);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return GeocodedLocation::fromArray($cached);
        }

        $location = $this->search($place, $country);

        if ($location !== null) {
            Cache::put($cacheKey, $location->toArray(), now()->addDays($this->cacheDays));
        }

        return $location;
    }

    private function search(string $place, Country $country): ?GeocodedLocation
    {
        try {
            $response = Http::withUserAgent($this->userAgent)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->retry([2000, 5000], when: fn (Throwable $e): bool => $this->isTransient($e), throw: false)
                ->get(rtrim($this->baseUrl, '/').'/search', array_filter([
                    'q' => $place,
                    'countrycodes' => strtolower($country->value),
                    'format' => 'jsonv2',
                    'limit' => 1,
                    'accept-language' => 'en',
                    'email' => $this->contactEmail,
                ]));
        } catch (ConnectionException $e) {
            throw DiscoveryException::transient('Could not reach the Nominatim geocoder: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw DiscoveryException::transient("The Nominatim geocoder returned HTTP {$response->status()}.");
        }

        $results = $response->json();

        if (! is_array($results) || ! array_is_list($results)) {
            throw DiscoveryException::transient('The Nominatim geocoder returned a malformed response.');
        }

        $first = $results[0] ?? null;

        if ($first === null) {
            return null;
        }

        $latitude = is_array($first) ? filter_var($first['lat'] ?? null, FILTER_VALIDATE_FLOAT) : false;
        $longitude = is_array($first) ? filter_var($first['lon'] ?? null, FILTER_VALIDATE_FLOAT) : false;

        if ($latitude === false || $longitude === false || abs($latitude) > 90 || abs($longitude) > 180) {
            throw DiscoveryException::transient('The Nominatim geocoder returned invalid coordinates.');
        }

        $displayName = Text::clean(is_string($first['display_name'] ?? null) ? $first['display_name'] : null, 500);

        return new GeocodedLocation($latitude, $longitude, $displayName ?? $place);
    }

    private function isTransient(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError()));
    }
}
