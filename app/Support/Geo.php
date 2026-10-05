<?php

namespace App\Support;

final class Geo
{
    private const EARTH_RADIUS_METERS = 6_371_000;

    /**
     * Great-circle distance between two coordinates, in metres (haversine).
     */
    public static function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($a)));
    }

    /**
     * Latitude/longitude deltas of a box enclosing a circle of the given radius.
     *
     * @return array{0: float, 1: float} [latitude delta, longitude delta]
     */
    public static function boundingDeltas(float $latitude, float $radiusMeters): array
    {
        $latDelta = $radiusMeters / 111_320;
        $lonDelta = $radiusMeters / (111_320 * max(cos(deg2rad($latitude)), 0.01));

        return [$latDelta, $lonDelta];
    }
}
