<?php

namespace App\Support;

/**
 * Resolves a host name to every IPv4/IPv6 address it points to. Bound in the
 * container so tests swap it for a fake (no real DNS in the suite).
 */
class HostResolver
{
    /**
     * @return list<string> Empty when the host does not resolve.
     */
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
