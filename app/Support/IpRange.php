<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * An IPv4 or IPv6 address or CIDR range, normalized to its network address
 * (e.g. "10.1.2.3/8" becomes "10.0.0.0/8"; a single address has no suffix).
 */
final class IpRange
{
    private function __construct(
        public readonly string $network,
        public readonly int $prefix,
        public readonly int $version,
        private readonly string $packed,
    ) {}

    public static function parse(string $value): self
    {
        $value = trim($value);
        [$address, $prefix] = str_contains($value, '/') ? explode('/', $value, 2) : [$value, null];

        $packed = @inet_pton($address);
        if ($packed === false || ! filter_var($address, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException("\"{$value}\" is not a valid IP address or range.");
        }

        $bits = strlen($packed) * 8;
        if ($prefix === null) {
            $prefix = $bits;
        } elseif (! ctype_digit($prefix) || (int) $prefix > $bits) {
            throw new InvalidArgumentException("\"{$value}\" has an invalid prefix length.");
        }

        $packed = self::mask($packed, (int) $prefix);

        return new self(inet_ntop($packed), (int) $prefix, $bits === 32 ? 4 : 6, $packed);
    }

    public static function isValid(string $value): bool
    {
        try {
            self::parse($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function isSingleAddress(): bool
    {
        return $this->prefix === ($this->version === 4 ? 32 : 128);
    }

    public function contains(string $ip): bool
    {
        $packed = @inet_pton($ip);

        return $packed !== false
            && strlen($packed) === strlen($this->packed)
            && self::mask($packed, $this->prefix) === $this->packed;
    }

    public function overlaps(self $other): bool
    {
        return $this->version === $other->version
            && ($this->contains($other->network) || $other->contains($this->network));
    }

    public function isLoopback(): bool
    {
        return self::parse('127.0.0.0/8')->overlaps($this) || self::parse('::1')->overlaps($this);
    }

    public function __toString(): string
    {
        return $this->isSingleAddress() ? $this->network : "{$this->network}/{$this->prefix}";
    }

    private static function mask(string $packed, int $prefix): string
    {
        $result = '';
        foreach (str_split($packed) as $i => $byte) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $result .= chr(ord($byte) & (0xFF << (8 - $bits)) & 0xFF);
        }

        return $result;
    }
}
