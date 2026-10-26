<?php

namespace App\Support;

use Closure;

/**
 * Checks that email for the email-to-fax domain will reach this server:
 *   1. the domain has MX records,
 *   2. each mail host resolves to an address,
 *   3. one of those addresses belongs to this server,
 *   4. the mail service answers on port 25 and accepts <number>@domain.
 *
 * Each step is reported as ok, warn or fail with a plain explanation. DNS and
 * SMTP access are injectable so tests don't need the network.
 */
class MxCheck
{
    /**
     * @param  (Closure(string): list<array{host: string, priority: int}>)|null  $mx
     * @param  (Closure(string): list<string>)|null  $resolve
     * @param  (Closure(string, string): array{ok: bool, detail: string})|null  $smtp
     * @param  list<string>|null  $serverIps
     */
    public function __construct(
        private ?Closure $mx = null,
        private ?Closure $resolve = null,
        private ?Closure $smtp = null,
        private ?array $serverIps = null,
    ) {}

    /**
     * The records an admin should create, with this server's address filled in.
     *
     * @return array{server_ips: list<string>, mail_host: string, subdomain: bool, records: list<array{type: string, name: string, value: string, priority: ?int}>}
     */
    public function suggestedRecords(string $domain, ?string $portalHost): array
    {
        $ips = $this->serverIps();
        $ipv4 = array_values(array_filter($ips, fn ($ip) => str_contains($ip, '.')));
        $ip = $ipv4[0] ?? ($ips[0] ?? '<this server\'s public IP>');

        // A portal hostname that already points here can be the mail host; otherwise the fax domain itself.
        $mailHost = $portalHost && HostResolver::isHostname($portalHost) && str_contains($portalHost, '.')
            && array_intersect($this->resolveHost($portalHost), $ips) ? $portalHost : $domain;

        $records = [];
        if ($mailHost === $domain) {
            $records[] = ['type' => 'A', 'name' => $domain, 'value' => $ip, 'priority' => null];
        }
        $records[] = ['type' => 'MX', 'name' => $domain, 'value' => $mailHost, 'priority' => 10];

        return [
            'server_ips' => $ips,
            'mail_host' => $mailHost,
            // fax.yourdomain.com has at least three labels; yourfaxdomain.com is a whole domain.
            'subdomain' => substr_count($domain, '.') >= 2,
            'records' => $records,
        ];
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public function run(string $domain): array
    {
        $results = [];
        $add = function (string $key, string $label, string $status, string $detail) use (&$results) {
            $results[] = compact('key', 'label', 'status', 'detail');
        };

        $mx = $this->mxRecords($domain);
        if ($mx === []) {
            $add('mx', 'MX record', 'fail', "No MX record found for {$domain}. Create one pointing at this server (see the records above). DNS changes can take up to an hour to appear.");

            return $results;
        }
        usort($mx, fn ($a, $b) => $a['priority'] <=> $b['priority']);
        $add('mx', 'MX record', 'ok', 'Found: '.implode(', ', array_map(fn ($r) => "{$r['host']} (priority {$r['priority']})", $mx)).'.');

        $serverIps = $this->serverIps();
        $pointsHere = null;
        $unresolved = [];
        foreach ($mx as $record) {
            $ips = $this->resolveHost($record['host']);
            if ($ips === []) {
                $unresolved[] = $record['host'];

                continue;
            }
            if (array_intersect($ips, $serverIps)) {
                $pointsHere ??= ['host' => $record['host'], 'ips' => $ips];
            }
        }

        if ($unresolved && count($unresolved) === count($mx)) {
            $add('resolve', 'Mail host address', 'fail', 'The mail host '.implode(', ', $unresolved).' has no A record. Add one with this server\'s IP address.');

            return $results;
        }
        $add('resolve', 'Mail host address', $unresolved ? 'warn' : 'ok', $unresolved
            ? 'These mail hosts have no address: '.implode(', ', $unresolved).'.'
            : 'Every mail host has an address.');

        $private = null;
        if (! $pointsHere) {
            foreach ($mx as $record) {
                if ($ips = array_intersect($this->resolveHost($record['host']), $this->serverIps(includePrivate: true))) {
                    $private = ['host' => $record['host'], 'ips' => array_values($ips)];
                    break;
                }
            }
        }

        if ($pointsHere) {
            $add('server', 'Points at this server', 'ok', "{$pointsHere['host']} resolves to ".implode(', ', $pointsHere['ips']).', which is this server.');
        } elseif ($private) {
            $add('server', 'Points at this server', 'warn', "{$private['host']} resolves to ".implode(', ', $private['ips'])
                .", this server's private address. Mail from the internet cannot reach a private address: point the A record at the public IP instead (the one that forwards to this server).");
        } else {
            $add('server', 'Points at this server', 'warn', 'The mail hosts resolve to '.implode(', ', array_unique(array_merge(...array_map(fn ($r) => $this->resolveHost($r['host']), $mx))))
                .', but this server\'s addresses are '.($serverIps ? implode(', ', $serverIps) : 'unknown')
                .'. That is expected behind NAT or a load balancer; otherwise update the A record.');
        }

        $target = $pointsHere['host'] ?? $mx[0]['host'];
        $smtp = ($this->smtp ?? $this->probeSmtp(...))($target, $domain);
        $add('smtp', 'Mail service', $smtp['ok'] ? 'ok' : 'warn', $smtp['detail']);

        return $results;
    }

    /**
     * Connect to the mail host on port 25 and ask whether it accepts mail for a fax number.
     *
     * @return array{ok: bool, detail: string}
     */
    private function probeSmtp(string $host, string $domain): array
    {
        $socket = @stream_socket_client("tcp://{$host}:25", $errno, $error, 6);
        if (! $socket) {
            return ['ok' => false, 'detail' => "Could not connect to {$host} on port 25 ({$error}). Check that port 25 is open on any external firewall. Some hosting providers also block port 25 on new servers until you ask."];
        }
        stream_set_timeout($socket, 6);

        $read = function () use ($socket): string {
            $reply = '';
            while (($line = fgets($socket)) !== false) {
                $reply .= $line;
                if (preg_match('/^\d{3} /', $line)) {
                    break;
                }
            }

            return $reply;
        };
        $send = function (string $command) use ($socket, $read): string {
            fwrite($socket, $command."\r\n");

            return $read();
        };

        $banner = $read();
        $send('EHLO mxcheck.'.$domain);
        $send('MAIL FROM:<mx-check@'.$domain.'>');
        $rcpt = $send('RCPT TO:<15550000000@'.$domain.'>');
        $send('QUIT');
        fclose($socket);

        if (! str_starts_with($banner, '220')) {
            return ['ok' => false, 'detail' => "{$host} answered on port 25 but not like a mail server."];
        }
        if (! str_starts_with($rcpt, '250')) {
            return ['ok' => false, 'detail' => "{$host} answered but would not accept mail for fax numbers at {$domain}: ".trim($rcpt)];
        }

        return ['ok' => true, 'detail' => "{$host} answered on port 25 and accepts mail for <number>@{$domain}."];
    }

    /**
     * @return list<array{host: string, priority: int}>
     */
    private function mxRecords(string $domain): array
    {
        if ($this->mx) {
            return ($this->mx)($domain);
        }

        return array_values(array_map(
            fn ($r) => ['host' => strtolower(rtrim($r['target'], '.')), 'priority' => (int) $r['pri']],
            array_filter(@dns_get_record($domain, DNS_MX) ?: [], fn ($r) => ($r['type'] ?? null) === 'MX'),
        ));
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        return $this->resolve ? ($this->resolve)($host) : app(HostResolver::class)->resolve($host);
    }

    /**
     * Addresses on this server's network interfaces: public ones only, unless
     * $includePrivate is set (loopback and link-local are always left out).
     *
     * @return list<string>
     */
    private function serverIps(bool $includePrivate = false): array
    {
        if ($this->serverIps !== null) {
            return $this->serverIps;
        }

        $ips = [];
        foreach (function_exists('net_get_interfaces') ? (net_get_interfaces() ?: []) : [] as $interface) {
            foreach ($interface['unicast'] ?? [] as $address) {
                $ip = $address['address'] ?? null;
                $flags = $includePrivate ? FILTER_FLAG_NO_RES_RANGE : FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
                if ($ip && filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
