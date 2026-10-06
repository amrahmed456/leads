<?php

namespace App\Discovery\Providers\Overture;

use App\Discovery\Contracts\BusinessDiscoveryProviderInterface;
use App\Discovery\Data\DiscoveryQuery;
use App\Discovery\Data\DiscoveryResult;
use App\Discovery\Exceptions\SourceNotApplicableException;
use App\Discovery\Providers\LocalPlaces\LocalPlaceSearch;

/**
 * Discovers businesses from imported Overture Places extracts.
 *
 * Overture is queried locally, never globally: only areas covered by an
 * imported extract (Settings → Data Sources → Overture) can be searched.
 *
 * @see https://docs.overturemaps.org/guides/places/
 */
final class OvertureProvider implements BusinessDiscoveryProviderInterface
{
    public const KEY = 'overture';

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
        return 'Overture Places';
    }

    public function discover(DiscoveryQuery $query): DiscoveryResult
    {
        if (! $this->places->hasCoverage(self::KEY, $query)) {
            throw new SourceNotApplicableException(
                'No imported Overture extract covers this area. Import one under Settings → Data Sources → Overture.'
            );
        }

        return $this->places->search(self::KEY, $query, $this->maxResults);
    }
}
