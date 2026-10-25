<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A connection to a SIP provider that delivers faxes to this server.
 *
 * "ip" trunks are trusted by source address and need no credentials.
 * "credentials" trunks register to the provider with a username and password.
 * Either way, the provider's addresses are whitelisted in the firewall and
 * are the only ones allowed to reach the SIP ports.
 */
class SipTrunk extends Model
{
    public const AUTH_TYPES = ['ip', 'credentials'];

    public const TRANSPORTS = ['udp', 'tcp', 'tls'];

    /**
     * How the provider wants dialled numbers: 15551234567, +15551234567, or
     * 5551234567 (national: the leading 1 dropped from North American numbers).
     */
    public const DIAL_FORMATS = ['e164', 'e164_plus', 'national'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'enabled',
        'auth_type',
        'transport',
        'host',
        'port',
        'dial_format',
        'username',
        'password',
        'extra_addresses',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'password' => 'encrypted',
            'extra_addresses' => 'array',
            'resolved_addresses' => 'array',
            'resolved_at' => 'datetime',
            'status_checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<FirewallRule, $this>
     */
    public function firewallRules(): HasMany
    {
        return $this->hasMany(FirewallRule::class);
    }

    /**
     * The gateway name used in FreeSWITCH. Based on the id, so renaming a
     * trunk does not leave a stale gateway behind.
     */
    public function gatewayName(): string
    {
        return "trunk-{$this->id}";
    }

    /**
     * The FreeSWITCH SIP profile the trunk belongs to: TLS needs its own listener.
     */
    public function profileName(): string
    {
        return $this->transport === 'tls' ? 'fax2email-tls' : 'fax2email';
    }

    /**
     * A stored number (digits with country code) as this provider expects it.
     */
    public function dialString(string $digits): string
    {
        return match ($this->dial_format) {
            'e164_plus' => "+{$digits}",
            'national' => strlen($digits) === 11 && $digits[0] === '1' ? substr($digits, 1) : $digits,
            default => $digits,
        };
    }

    public function registers(): bool
    {
        return $this->auth_type === 'credentials';
    }

    /**
     * The host and the extra addresses: everything that must be whitelisted.
     *
     * @return list<string>
     */
    public function configuredAddresses(): array
    {
        return array_values(array_unique([$this->host, ...($this->extra_addresses ?? [])]));
    }
}
