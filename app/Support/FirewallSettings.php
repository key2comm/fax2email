<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;

/**
 * fail2ban tuning that admins change on the Firewall page.
 */
class FirewallSettings
{
    public const KEY = 'firewall';

    /**
     * The fail2ban jails the app manages, keyed by jail name.
     */
    public const JAILS = [
        'f2e_sip' => 'SIP (FreeSWITCH)',
        'f2e_portal' => 'Portal login',
        'f2e_ssh' => 'SSH',
    ];

    public const RECIDIVE_JAIL = 'f2e_recidive';

    public const DEFAULTS = [
        'max_retry' => 5,
        'find_time_minutes' => 10,
        'ban_time_hours' => 24,
        'recidive_max_retry' => 3,
        'recidive_find_time_days' => 7,
        'recidive_ban_time_days' => 30,
        'jail_f2e_sip' => true,
        'jail_f2e_portal' => true,
        'jail_f2e_ssh' => true,
    ];

    /**
     * @return array<string, int|bool>
     */
    public static function get(): array
    {
        try {
            $stored = Setting::read(self::KEY) ?? [];
        } catch (QueryException|DecryptException) {
            $stored = [];
        }

        return [...self::DEFAULTS, ...array_intersect_key($stored, self::DEFAULTS)];
    }

    public static function jailLabel(?string $jail): string
    {
        return match ($jail) {
            null => 'Manual',
            self::RECIDIVE_JAIL => 'Repeat offender',
            default => self::JAILS[$jail] ?? $jail,
        };
    }
}
