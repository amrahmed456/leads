<?php

namespace App\Discovery\Providers\AllThePlaces;

use App\Discovery\Contracts\BusinessDiscoveryProviderInterface;
use App\Discovery\Data\DiscoveryQuery;
use App\Discovery\Data\DiscoveryResult;
use App\Discovery\Providers\LocalPlaces\LocalPlaceSearch;

/**
 * Discovers branches of brands with a synced AllThePlaces spider in the
 * campaign area. Every result is first-party chain evidence, so these
 * locations are expected to be classified as chain branches; their main
 * value is confirming chain membership of businesses found elsewhere.
 */
final class AllThePlacesProvider implements BusinessDiscoveryProviderInterface
{
    public const KEY = 'alltheplaces';

    public function __construct(
        private readonly LocalPlaceSearch $places,
        private readonly int $maxResults,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'AllThePlaces';
    }

    public function discover(DiscoveryQuery $query): DiscoveryResult
    {
        return $this->places->search(self::KEY, $query, $this->maxResults);
    }
}
