<?php

namespace App\Discovery\Geocoding;

use App\Discovery\Contracts\GeocoderInterface;
use App\Discovery\Data\GeocodedLocation;
use App\Enums\Country;
use App\Sources\GeoNames\CityResolver;

/**
 * Locates campaign cities with GeoNames (imported cities, multilingual
 * names) and falls back to another geocoder for districts, streets and
 * anything GeoNames does not know.
 */
final class GeoNamesFirstGeocoder implements GeocoderInterface
{
    public function __construct(
        private readonly CityResolver $cities,
        private readonly GeocoderInterface $fallback,
    ) {}

    public function geocode(string $place, Country $country): ?GeocodedLocation
    {
        $city = $this->cities->resolve($place, $country);

        if ($city !== null) {
            return new GeocodedLocation($city->latitude, $city->longitude, "{$city->name}, {$country->label()} (GeoNames)");
        }

        return $this->fallback->geocode($place, $country);
    }
}
