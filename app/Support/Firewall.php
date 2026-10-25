<?php

namespace App\Support;

use App\Models\FirewallEvent;
use App\Models\FirewallRule;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The bridge between the portal and the root-owned fax2email-firewall-apply
 * service. The web app never touches the firewall itself: it records the
 * desired state, touches a trigger file, and the service (started by a
 * systemd path unit) reads the export below and applies it.
 */
class Firewall
{
    public const STATUS_KEY = 'firewall_status';

    public static function triggerFile(): string
    {
        return storage_path('app/firewall/changed');
    }

    /**
     * Ask the firewall service to apply the current rules.
     */
    public static function requestApply(): void
    {
        if (! is_dir(dirname(self::triggerFile()))) {
            mkdir(dirname(self::triggerFile()), 0775, true);
        }

        file_put_contents(self::triggerFile(), now()->toIso8601String()."\n");
    }

    public static function record(string $action, string $cidr, ?string $jail = null, ?int $userId = null, ?string $note = null): void
    {
        FirewallEvent::create(['action' => $action, 'cidr' => $cidr, 'jail' => $jail, 'user_id' => $userId, 'note' => $note]);
    }

    /**
     * The desired state, one instruction per line, for the apply service:
     *
     *   allow <cidr>
     *   trunk <cidr>           (a SIP provider; only these may reach the SIP ports)
     *   block <cidr> <seconds left, 0 = permanent>
     *   unban <jail> <ip>
     *   jail <name> <0|1>
     *   setting <name> <integer>
     */
    public static function export(): string
    {
        $lines = [];

        $allowed = FirewallRule::where('type', FirewallRule::ALLOW)->orderBy('cidr')->get(['cidr', 'source']);
        foreach ($allowed->pluck('cidr')->unique() as $cidr) {
            $lines[] = "allow {$cidr}";
        }
        foreach ($allowed->where('source', 'trunk')->pluck('cidr')->unique() as $cidr) {
            $lines[] = "trunk {$cidr}";
        }

        // nftables rejects overlapping ranges in one set, so skip any block
        // already covered by a wider one (widest ranges are kept first).
        $manualBlocks = FirewallRule::where('type', FirewallRule::BLOCK)->where('source', 'manual')->active()->get()
            ->sortBy(fn (FirewallRule $rule) => IpRange::parse($rule->cidr)->prefix);
        $kept = [];
        foreach ($manualBlocks as $rule) {
            $range = IpRange::parse($rule->cidr);
            if (array_filter($kept, fn (IpRange $wider) => $wider->overlaps($range))) {
                continue;
            }
            $kept[] = $range;
            $seconds = $rule->expires_at ? max(1, (int) now()->diffInSeconds($rule->expires_at)) : 0;
            $lines[] = "block {$rule->cidr} {$seconds}";
        }

        $unbans = FirewallRule::where('source', 'fail2ban')->whereNotNull('unban_requested_at')->get();
        foreach ($unbans as $rule) {
            $lines[] = "unban {$rule->jail} {$rule->cidr}";
        }

        $settings = FirewallSettings::get();
        foreach (array_keys(FirewallSettings::JAILS) as $jail) {
            $lines[] = "jail {$jail} ".($settings["jail_{$jail}"] ? 1 : 0);
        }
        foreach ($settings as $name => $value) {
            if (! str_starts_with($name, 'jail_')) {
                $lines[] = "setting {$name} {$value}";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Mirror fail2ban's current bans into firewall_rules. Each input line is
     * "<jail> <ip> <unix time the ban ends>". Bans missing from the input have
     * ended (or were lifted), so their rules are removed.
     *
     * @param  list<string>  $lines
     * @return array{banned: int, unbanned: int}
     */
    public static function importBans(array $lines): array
    {
        $current = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) !== 3 || ! ctype_digit($parts[2]) || ! IpRange::isValid($parts[1])) {
                continue;
            }
            [$jail, $ip, $endsAt] = $parts;
            if ($jail !== FirewallSettings::RECIDIVE_JAIL && ! isset(FirewallSettings::JAILS[$jail])) {
                continue;
            }
            $current[$jail.' '.IpRange::parse($ip)] = Carbon::createFromTimestamp((int) $endsAt);
        }

        $banned = $unbanned = 0;

        DB::transaction(function () use ($current, &$banned, &$unbanned) {
            $existing = FirewallRule::where('source', 'fail2ban')->get()->keyBy(fn (FirewallRule $rule) => "{$rule->jail} {$rule->cidr}");

            foreach ($current as $key => $endsAt) {
                [$jail, $ip] = explode(' ', $key, 2);
                $rule = $existing->get($key);

                if ($rule) {
                    $rule->update(['expires_at' => $endsAt]);

                    continue;
                }

                FirewallRule::create([
                    'type' => FirewallRule::BLOCK,
                    'cidr' => $ip,
                    'source' => 'fail2ban',
                    'jail' => $jail,
                    'expires_at' => $endsAt,
                ]);
                self::record('banned', $ip, $jail, note: 'Banned until '.$endsAt->toDateTimeString().' UTC');
                $banned++;
            }

            foreach ($existing->reject(fn (FirewallRule $rule, string $key) => isset($current[$key])) as $rule) {
                $rule->delete();
                self::record('unbanned', $rule->cidr, $rule->jail, note: $rule->unban_requested_at ? 'Lifted by an admin' : 'Ban expired');
                $unbanned++;
            }
        });

        return ['banned' => $banned, 'unbanned' => $unbanned];
    }

    /**
     * @return array{applied_at: ?string, error: ?string, fail2ban: ?bool}
     */
    public static function status(): array
    {
        try {
            $status = Setting::read(self::STATUS_KEY) ?? [];
        } catch (QueryException|DecryptException) {
            $status = [];
        }

        return [
            'applied_at' => $status['applied_at'] ?? null,
            'error' => $status['error'] ?? null,
            'fail2ban' => $status['fail2ban'] ?? null,
        ];
    }

    public static function recordStatus(?string $error, bool $fail2banRunning): void
    {
        Setting::write(self::STATUS_KEY, [
            'applied_at' => now()->toIso8601String(),
            'error' => $error,
            'fail2ban' => $fail2banRunning,
        ]);
    }
}
