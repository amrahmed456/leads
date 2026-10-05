<?php

namespace App\Discovery\Data;

use App\Enums\BusinessCategory;
use App\Enums\Country;

/**
 * Provider-independent description of what to search for.
 */
final readonly class DiscoveryQuery
{
    /**
     * @param  list<string>  $keywords  Extra name keywords (any language)
     */
    public function __construct(
        public Country $country,
        public string $city,
        public float $latitude,
        public float $longitude,
        public int $radiusMeters,
        public BusinessCategory $category,
        public array $keywords = [],
    ) {}
}
