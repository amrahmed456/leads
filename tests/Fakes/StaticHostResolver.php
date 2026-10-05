<?php

namespace Tests\Fakes;

use App\Auditing\Contracts\HostResolver;

/**
 * Resolves host names from a fixed map so tests never perform DNS lookups.
 * Unknown hosts resolve to a public address unless $default is null.
 */
final class StaticHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $hosts
     */
    public function __construct(
        private readonly array $hosts = [],
        private readonly ?string $default = '93.184.215.14',
    ) {}

    public function resolve(string $host): array
    {
        return $this->hosts[$host] ?? ($this->default === null ? [] : [$this->default]);
    }
}
