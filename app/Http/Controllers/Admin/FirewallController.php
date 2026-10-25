<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FirewallEvent;
use App\Models\FirewallRule;
use App\Models\Setting;
use App\Support\Firewall;
use App\Support\FirewallSettings;
use App\Support\IpRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FirewallController extends Controller
{
    /**
     * Manual block durations offered in the portal, in seconds (null = permanent).
     */
    private const DURATIONS = [
        'permanent' => null,
        '1h' => 3600,
        '1d' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ];

    public function blocked(Request $request): Response
    {
        Gate::authorize('manage-settings');

        $rules = FirewallRule::where('type', FirewallRule::BLOCK)->active()->with('creator:id,name')->latest()->get();

        // How often each address was banned recently, to spot repeat offenders.
        $banCounts = FirewallEvent::where('action', 'banned')
            ->where('created_at', '>', now()->subDays(30))
            ->whereIn('cidr', $rules->pluck('cidr'))
            ->selectRaw('cidr, count(*) as total')
            ->groupBy('cidr')
            ->pluck('total', 'cidr');

        return $this->render($request, 'admin/firewall/Blocked', [
            'rules' => $rules->map(fn (FirewallRule $rule) => [
                ...$this->ruleRow($rule),
                'bans_30d' => (int) ($banCounts[$rule->cidr] ?? 0),
            ]),
            'durations' => array_keys(self::DURATIONS),
        ]);
    }

    public function whitelist(Request $request): Response
    {
        Gate::authorize('manage-settings');

        return $this->render($request, 'admin/firewall/Whitelist', [
            'rules' => FirewallRule::where('type', FirewallRule::ALLOW)->with('creator:id,name')->orderBy('cidr')->get()->map($this->ruleRow(...)),
        ]);
    }

    public function settings(Request $request): Response
    {
        Gate::authorize('manage-settings');

        return $this->render($request, 'admin/firewall/Settings', [
            'settings' => FirewallSettings::get(),
            'jails' => FirewallSettings::JAILS,
        ]);
    }

    public function activity(Request $request): Response
    {
        Gate::authorize('manage-settings');

        $search = trim((string) $request->query('search', ''));

        $events = FirewallEvent::query()
            ->when($search !== '', fn ($query) => $query->where('cidr', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%'))
            ->with('user:id,name')
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (FirewallEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'cidr' => $event->cidr,
                'source' => FirewallSettings::jailLabel($event->jail),
                'user' => $event->user?->name,
                'note' => $event->note,
                'created_at' => $event->created_at->toIso8601String(),
            ]);

        return $this->render($request, 'admin/firewall/Activity', [
            'events' => $events,
            'search' => $search,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $validated = $request->validate([
            'type' => ['required', Rule::in([FirewallRule::ALLOW, FirewallRule::BLOCK])],
            'cidr' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:255'],
            'duration' => ['nullable', Rule::in(array_keys(self::DURATIONS))],
        ]);

        $range = $this->parseRange($validated['cidr']);
        $type = $validated['type'];

        if ($type === FirewallRule::BLOCK) {
            $this->guardAgainstLockout($range, $request->ip());
        }

        if (FirewallRule::where('type', $type)->where('source', 'manual')->where('cidr', (string) $range)->exists()) {
            throw ValidationException::withMessages(['cidr' => "{$range} is already on the ".($type === FirewallRule::ALLOW ? 'whitelist.' : 'block list.')]);
        }

        $seconds = $type === FirewallRule::BLOCK ? self::DURATIONS[$validated['duration'] ?? 'permanent'] : null;

        FirewallRule::create([
            'type' => $type,
            'cidr' => (string) $range,
            'note' => $validated['note'] ?? null,
            'created_by' => $request->user()->id,
            'expires_at' => $seconds ? now()->addSeconds($seconds) : null,
        ]);
        Firewall::record($type === FirewallRule::ALLOW ? 'whitelisted' : 'blocked', (string) $range, userId: $request->user()->id, note: $validated['note'] ?? null);
        Firewall::requestApply();

        return back()->with('status', $type === FirewallRule::ALLOW ? "{$range} added to the whitelist." : "{$range} blocked.");
    }

    public function destroy(Request $request, FirewallRule $rule): RedirectResponse
    {
        Gate::authorize('manage-settings');

        if ($rule->source === 'trunk') {
            return back()->withErrors(['cidr' => "{$rule->cidr} belongs to a SIP trunk. Edit or delete the trunk under SIP trunks instead."]);
        }

        if ($rule->source === 'fail2ban') {
            // fail2ban owns this ban; ask the apply service to lift it there.
            $rule->forceFill(['unban_requested_at' => now()])->save();
            Firewall::record('unban requested', $rule->cidr, $rule->jail, $request->user()->id);
            $message = "Lifting the ban on {$rule->cidr}.";
        } else {
            $rule->delete();
            Firewall::record($rule->type === FirewallRule::ALLOW ? 'removed from whitelist' : 'unblocked', $rule->cidr, userId: $request->user()->id);
            $message = $rule->type === FirewallRule::ALLOW ? "{$rule->cidr} removed from the whitelist." : "{$rule->cidr} unblocked.";
        }

        Firewall::requestApply();

        return back()->with('status', $message);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $rules = [
            'max_retry' => ['required', 'integer', 'between:1,100'],
            'find_time_minutes' => ['required', 'integer', 'between:1,1440'],
            'ban_time_hours' => ['required', 'integer', 'between:1,8760'],
            'recidive_max_retry' => ['required', 'integer', 'between:2,20'],
            'recidive_find_time_days' => ['required', 'integer', 'between:1,30'],
            'recidive_ban_time_days' => ['required', 'integer', 'between:1,365'],
        ];
        foreach (array_keys(FirewallSettings::JAILS) as $jail) {
            $rules["jail_{$jail}"] = ['required', 'boolean'];
        }

        $validated = $request->validate($rules);

        if ($validated['ban_time_hours'] >= $validated['recidive_ban_time_days'] * 24) {
            throw ValidationException::withMessages(['recidive_ban_time_days' => 'Repeat offenders must be banned for longer than a first ban.']);
        }

        $settings = array_map(fn ($value) => is_bool($value) ? $value : (int) $value, $validated);
        foreach (array_keys(FirewallSettings::JAILS) as $jail) {
            $settings["jail_{$jail}"] = (bool) $validated["jail_{$jail}"];
        }

        Setting::write(FirewallSettings::KEY, $settings);
        Firewall::requestApply();

        return back()->with('status', 'Firewall settings saved.');
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function render(Request $request, string $page, array $props): Response
    {
        return Inertia::render($page, [
            ...$props,
            'status' => Firewall::status(),
            'yourIp' => $request->ip(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleRow(FirewallRule $rule): array
    {
        return [
            'id' => $rule->id,
            'cidr' => $rule->cidr,
            'source' => $rule->source === 'trunk' ? 'Provider trunk' : FirewallSettings::jailLabel($rule->jail),
            'is_fail2ban' => $rule->source === 'fail2ban',
            'is_trunk' => $rule->source === 'trunk',
            'note' => $rule->note,
            'created_by' => $rule->creator?->name,
            'created_at' => $rule->created_at->toIso8601String(),
            'expires_at' => $rule->expires_at?->toIso8601String(),
            'unban_requested' => $rule->unban_requested_at !== null,
        ];
    }

    private function parseRange(string $value): IpRange
    {
        try {
            $range = IpRange::parse($value);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['cidr' => $e->getMessage()]);
        }

        if ($range->prefix < ($range->version === 4 ? 8 : 32)) {
            throw ValidationException::withMessages(['cidr' => 'That range is too large. Use /8 or smaller for IPv4, /32 or smaller for IPv6.']);
        }

        return $range;
    }

    /**
     * Refuse blocks that would cut off the admin, the server itself, or a whitelisted range.
     */
    private function guardAgainstLockout(IpRange $range, ?string $yourIp): void
    {
        if ($range->isLoopback()) {
            throw ValidationException::withMessages(['cidr' => 'The server\'s own loopback address cannot be blocked.']);
        }

        if ($yourIp && $range->contains($yourIp)) {
            throw ValidationException::withMessages(['cidr' => "That would block your own address ({$yourIp}) and lock you out."]);
        }

        foreach (FirewallRule::where('type', FirewallRule::ALLOW)->pluck('cidr') as $allowed) {
            if ($range->overlaps(IpRange::parse($allowed))) {
                throw ValidationException::withMessages(['cidr' => "That overlaps the whitelisted range {$allowed}. Remove it from the whitelist first."]);
            }
        }
    }
}
