<?php

namespace App\Support;

use App\Models\FirewallRule;
use App\Models\SipTrunk;
use Illuminate\Support\Facades\DB;

/**
 * Keeps each SIP trunk's provider addresses on the firewall whitelist
 * ("Provider trunk" entries). Hostnames are resolved here, and again every
 * few minutes by the scheduler in case the provider's IPs change.
 */
class TrunkWhitelist
{
    public function __construct(private HostResolver $resolver) {}

    /**
     * Resolve the trunk's addresses and update its whitelist entries.
     *
     * @return bool Whether the whitelist changed.
     */
    public function sync(SipTrunk $trunk): bool
    {
        $resolved = [];
        $unresolved = [];
        foreach ($trunk->configuredAddresses() as $address) {
            $ips = $this->resolver->resolve($address);
            $ips === [] ? $unresolved[] = $address : array_push($resolved, ...$ips);
        }
        $resolved = array_values(array_unique($resolved));
        sort($resolved);

        // Keep the last known addresses if DNS is temporarily failing, rather than locking the provider out.
        if ($resolved === [] && $trunk->resolved_addresses) {
            $resolved = $trunk->resolved_addresses;
        }

        $trunk->forceFill([
            'resolved_addresses' => $resolved,
            'resolved_at' => now(),
            'resolve_error' => $unresolved ? 'Could not look up: '.implode(', ', $unresolved) : null,
        ])->saveQuietly();

        $wanted = $trunk->enabled ? $resolved : [];

        $changed = DB::transaction(function () use ($trunk, $wanted) {
            $existing = $trunk->firewallRules()->get()->keyBy('cidr');
            $changed = false;

            foreach (array_diff($wanted, $existing->keys()->all()) as $cidr) {
                $trunk->firewallRules()->create([
                    'type' => FirewallRule::ALLOW,
                    'cidr' => $cidr,
                    'source' => 'trunk',
                    'note' => "Provider trunk: {$trunk->name}",
                ]);
                Firewall::record('whitelisted', $cidr, note: "Provider trunk: {$trunk->name}");
                $changed = true;
            }

            foreach ($existing->reject(fn (FirewallRule $rule, string $cidr) => in_array($cidr, $wanted, true)) as $rule) {
                $rule->delete();
                Firewall::record('removed from whitelist', $rule->cidr, note: "Provider trunk: {$trunk->name}");
                $changed = true;
            }

            // Keep the label current if the trunk was renamed.
            $trunk->firewallRules()->update(['note' => "Provider trunk: {$trunk->name}"]);

            return $changed;
        });

        if ($changed) {
            Firewall::requestApply();
        }

        return $changed;
    }

    /**
     * Remove a trunk's whitelist entries (called before the trunk is deleted).
     */
    public function remove(SipTrunk $trunk): void
    {
        foreach ($trunk->firewallRules as $rule) {
            Firewall::record('removed from whitelist', $rule->cidr, note: "Provider trunk: {$trunk->name} (deleted)");
        }
        $trunk->firewallRules()->delete();
        Firewall::requestApply();
    }
}
