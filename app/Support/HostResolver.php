<?php

namespace App\Support;

/**
 * Turns a provider address (IP, CIDR range or hostname) into IP ranges.
 * Bound in the container so tests can swap in fixed answers.
 */
class HostResolver
{
    /**
     * @return list<string> Normalized IPs/ranges; empty if a hostname does not resolve.
     */
    public function resolve(string $address): array
    {
        if (IpRange::isValid($address)) {
            return [(string) IpRange::parse($address)];
        }

        $records = @dns_get_record($address, DNS_A | DNS_AAAA) ?: [];
        $ips = array_merge(
            array_column(array_filter($records, fn ($r) => ($r['type'] ?? null) === 'A'), 'ip'),
            array_column(array_filter($records, fn ($r) => ($r['type'] ?? null) === 'AAAA'), 'ipv6'),
        );

        // dns_get_record skips /etc/hosts; fall back to the system resolver.
        if ($ips === []) {
            $ips = @gethostbynamel($address) ?: [];
        }

        $ips = array_map(fn (string $ip) => (string) IpRange::parse($ip), $ips);
        sort($ips);

        return array_values(array_unique($ips));
    }

    public static function isHostname(string $value): bool
    {
        return strlen($value) <= 253
            && (bool) preg_match('/^(?=.*[a-z])([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $value);
    }
}
