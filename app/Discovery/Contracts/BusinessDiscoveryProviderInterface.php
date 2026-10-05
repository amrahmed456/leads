<?php

namespace App\Discovery\Contracts;

use App\Discovery\Data\DiscoveryQuery;
use App\Discovery\Data\DiscoveryResult;
use App\Discovery\Exceptions\DiscoveryException;

/**
 * A source of real business listings (OpenStreetMap, Google Places, ...).
 *
 * Implementations translate a provider-independent DiscoveryQuery into the
 * provider's own API, and translate the response back into provider-
 * independent DiscoveredBusiness objects. Nothing outside the provider
 * should depend on the shape of the external API.
 */
interface BusinessDiscoveryProviderInterface
{
    /**
     * Stable machine name stored with every source record, e.g. "openstreetmap".
     */
    public function key(): string;

    /**
     * Human readable name shown in the UI.
     */
    public function label(): string;

    /**
     * Find businesses matching the query.
     *
     * @throws DiscoveryException when the provider cannot be queried or
     *                            returns an unusable response
     */
    public function discover(DiscoveryQuery $query): DiscoveryResult;
}
