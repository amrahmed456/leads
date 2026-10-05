<?php

namespace App\Discovery\Data;

final readonly class DiscoveryResult
{
    /**
     * @param  list<DiscoveredBusiness>  $businesses
     * @param  int  $skipped  Provider records ignored as malformed, unnamed or not a business
     * @param  bool  $truncated  Whether the provider stopped at its result limit
     */
    public function __construct(
        public array $businesses,
        public int $skipped = 0,
        public bool $truncated = false,
    ) {}
}
