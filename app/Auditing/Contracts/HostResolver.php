<?php

namespace App\Auditing\Contracts;

interface HostResolver
{
    /**
     * The IP addresses a host name resolves to; empty when it does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
