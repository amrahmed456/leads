<?php

namespace App\Auditing;

use App\Auditing\Contracts\HostResolver;

final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = gethostbynamel($host) ?: [];

        if ($addresses === []) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
