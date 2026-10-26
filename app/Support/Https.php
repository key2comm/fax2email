<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * HTTPS for the portal. The web app only records what the admin wants; the
 * root-owned fax2email-https-apply service (started by a systemd path unit)
 * gets or installs the certificate, rewrites the Nginx config (tested, and
 * rolled back if it fails), and reports back through status().
 */
class Https
{
    public const KEY = 'https';

    public const STATUS_KEY = 'https_status';

    public const MODES = ['none', 'letsencrypt', 'custom'];

    /** How often an automatically renewed Let's Encrypt certificate is replaced, in days. */
    public const RENEW_DAYS = [30, 45, 60];

    public static function dir(): string
    {
        return storage_path('app/https');
    }

    /**
     * @return array{mode: string, domain: ?string, email: ?string, redirect: bool, auto_renew: bool, renew_days: int, force_renew: bool}
     */
    public static function settings(): array
    {
        return [...['mode' => 'none', 'domain' => null, 'email' => null, 'redirect' => true, 'auto_renew' => true, 'renew_days' => 30, 'force_renew' => false], ...self::read(self::KEY)];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function save(array $settings): void
    {
        $settings = [...self::settings(), ...$settings];
        Setting::write(self::KEY, $settings);
        self::recordStatus(['state' => $settings['mode'] === 'none' ? 'off' : 'pending', 'message' => null]);
        self::requestApply();
    }

    /**
     * @return array{state: string, message: ?string, domain: ?string, issuer: ?string, issued_at: ?string, expires_at: ?string, checked_at: ?string}
     */
    public static function status(): array
    {
        return [...['state' => 'off', 'message' => null, 'domain' => null, 'issuer' => null, 'issued_at' => null, 'expires_at' => null, 'checked_at' => null], ...self::read(self::STATUS_KEY)];
    }

    /**
     * When the certificate will next be renewed, or null if it won't be automatically.
     */
    public static function nextRenewal(): ?string
    {
        $s = self::settings();
        $issued = self::status()['issued_at'];
        if ($s['mode'] !== 'letsencrypt' || ! $s['auto_renew'] || ! $issued) {
            return null;
        }

        return Carbon::parse($issued)->addDays($s['renew_days'])->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public static function recordStatus(array $status): void
    {
        Setting::write(self::STATUS_KEY, [...self::status(), ...$status, 'checked_at' => now()->toIso8601String()]);
    }

    public static function requestApply(): void
    {
        if (! is_dir(self::dir())) {
            mkdir(self::dir(), 0770, true);
        }
        file_put_contents(self::dir().'/request', now()->toIso8601String()."\n");
    }

    /**
     * For the apply service: one "key value" pair per line.
     */
    public static function export(): string
    {
        $s = self::settings();

        return implode("\n", [
            "mode {$s['mode']}",
            'domain '.($s['domain'] ?? ''),
            'email '.($s['email'] ?? ''),
            'redirect '.($s['redirect'] ? 1 : 0),
            'auto_renew '.($s['auto_renew'] ? 1 : 0),
            'renew_days '.(in_array((int) $s['renew_days'], self::RENEW_DAYS, true) ? (int) $s['renew_days'] : 30),
            'force_renew '.($s['force_renew'] ? 1 : 0),
        ])."\n";
    }

    /**
     * Check an uploaded certificate and key before handing them to the apply
     * service. Returns the names the certificate covers and when it expires.
     *
     * @return array{names: list<string>, expires_at: Carbon}
     */
    public static function checkCertificate(string $certificatePem, string $keyPem): array
    {
        $cert = @openssl_x509_read($certificatePem);
        if ($cert === false) {
            throw new InvalidArgumentException('That is not a valid certificate. Paste the PEM text that starts with -----BEGIN CERTIFICATE-----.');
        }
        $key = @openssl_pkey_get_private($keyPem);
        if ($key === false) {
            throw new InvalidArgumentException('That is not a valid private key, or it is protected by a passphrase. Paste the unencrypted PEM key.');
        }
        if (! openssl_x509_check_private_key($cert, $key)) {
            throw new InvalidArgumentException('The private key does not belong to this certificate.');
        }

        $info = openssl_x509_parse($cert);
        $expires = Carbon::createFromTimestamp($info['validTo_time_t']);
        if ($expires->isPast()) {
            throw new InvalidArgumentException('This certificate expired on '.$expires->toFormattedDateString().'.');
        }

        $names = [];
        foreach (explode(',', $info['extensions']['subjectAltName'] ?? '') as $entry) {
            if (str_starts_with(trim($entry), 'DNS:')) {
                $names[] = strtolower(substr(trim($entry), 4));
            }
        }
        if ($names === [] && isset($info['subject']['CN'])) {
            $names[] = strtolower($info['subject']['CN']);
        }

        return ['names' => array_values(array_unique($names)), 'expires_at' => $expires];
    }

    public static function covers(array $names, string $domain): bool
    {
        foreach ($names as $name) {
            if ($name === $domain || (str_starts_with($name, '*.') && substr_count($domain, '.') === substr_count($name, '.') && str_ends_with($domain, substr($name, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $key): array
    {
        try {
            return Setting::read($key) ?? [];
        } catch (QueryException|DecryptException) {
            return [];
        }
    }
}
