<?php

namespace App\Discovery\Contracts;

use App\Discovery\Data\GeocodedLocation;
use App\Discovery\Exceptions\DiscoveryException;
use App\Enums\Country;

interface GeocoderInterface
{
    /**
     * Resolve a city or area name inside a country to coordinates.
     *
     * Returns null when the place cannot be found.
     *
     * @throws DiscoveryException when the geocoding service is unavailable
     */
    public function geocode(string $place, Country $country): ?GeocodedLocation;
}
