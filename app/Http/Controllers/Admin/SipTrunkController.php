<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ReloadFreeswitchTrunks;
use App\Models\SipTrunk;
use App\Support\HostResolver;
use App\Support\IpRange;
use App\Support\TrunkWhitelist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SipTrunkController extends Controller
{
    public function __construct(private TrunkWhitelist $whitelist) {}

    public function index(): Response
    {
        Gate::authorize('manage-settings');

        return Inertia::render('admin/trunks/Index', [
            'trunks' => SipTrunk::orderBy('name')->get()->map(fn (SipTrunk $trunk) => [
                'id' => $trunk->id,
                'name' => $trunk->name,
                'enabled' => $trunk->enabled,
                'auth_type' => $trunk->auth_type,
                'transport' => $trunk->transport,
                'host' => $trunk->host,
                'port' => $trunk->port,
                'username' => $trunk->username,
                'addresses' => $trunk->resolved_addresses ?? [],
                'resolve_error' => $trunk->resolve_error,
                'status' => $trunk->status,
                'status_detail' => $trunk->status_detail,
                'status_checked_at' => $trunk->status_checked_at?->toIso8601String(),
            ]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('manage-settings');

        return Inertia::render('admin/trunks/Form', ['trunk' => null]);
    }

    public function edit(SipTrunk $trunk): Response
    {
        Gate::authorize('manage-settings');

        return Inertia::render('admin/trunks/Form', [
            'trunk' => [
                ...$trunk->only('id', 'name', 'enabled', 'auth_type', 'transport', 'host', 'port', 'dial_format', 'username'),
                'extra_addresses' => implode("\n", $trunk->extra_addresses ?? []),
                'has_password' => filled($trunk->password),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $trunk = SipTrunk::create($this->validated($request));
        $this->whitelist->sync($trunk);
        ReloadFreeswitchTrunks::dispatch();

        return to_route('admin.trunks.index')->with('status', "Trunk {$trunk->name} added. Its addresses are now whitelisted.");
    }

    public function update(Request $request, SipTrunk $trunk): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $old = ['profile' => $trunk->profileName(), 'gateway' => $trunk->gatewayName()];
        $trunk->update($this->validated($request, $trunk));
        $this->whitelist->sync($trunk);
        // A gateway's settings only change in FreeSWITCH after it is stopped and loaded again.
        ReloadFreeswitchTrunks::dispatch([$old]);

        return to_route('admin.trunks.index')->with('status', "Trunk {$trunk->name} saved.");
    }

    public function destroy(SipTrunk $trunk): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $gateway = ['profile' => $trunk->profileName(), 'gateway' => $trunk->gatewayName()];
        $this->whitelist->remove($trunk);
        $trunk->delete();
        ReloadFreeswitchTrunks::dispatch([$gateway]);

        return to_route('admin.trunks.index')->with('status', "Trunk {$trunk->name} deleted and removed from the whitelist.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SipTrunk $trunk = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique(SipTrunk::class)->ignore($trunk)],
            'enabled' => ['required', 'boolean'],
            'auth_type' => ['required', Rule::in(SipTrunk::AUTH_TYPES)],
            'transport' => ['required', Rule::in(SipTrunk::TRANSPORTS)],
            'host' => ['required', 'string', 'max:253'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'dial_format' => ['required', Rule::in(SipTrunk::DIAL_FORMATS)],
            'username' => ['nullable', 'required_if:auth_type,credentials', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'extra_addresses' => ['nullable', 'string', 'max:5000'],
        ], [
            'username.required_if' => 'A username is required for username/password trunks.',
        ]);

        $validated['host'] = strtolower(trim($validated['host']));
        $this->checkAddress('host', $validated['host'], allowRanges: false);

        $extra = preg_split('/[\s,]+/', strtolower((string) ($validated['extra_addresses'] ?? '')), flags: PREG_SPLIT_NO_EMPTY);
        foreach ($extra as $address) {
            $this->checkAddress('extra_addresses', $address, allowRanges: true);
        }
        $validated['extra_addresses'] = array_values(array_unique($extra));

        if ($validated['auth_type'] === 'credentials') {
            // A blank password keeps the saved one, so it never has to be sent back to the browser.
            $validated['password'] = filled($validated['password'] ?? null) ? $validated['password'] : $trunk?->password;
            if (blank($validated['password'])) {
                throw ValidationException::withMessages(['password' => 'A password is required for username/password trunks.']);
            }
        } else {
            $validated['username'] = null;
            $validated['password'] = null;
        }

        return $validated;
    }

    private function checkAddress(string $field, string $address, bool $allowRanges): void
    {
        if (IpRange::isValid($address)) {
            $range = IpRange::parse($address);
            if (! $allowRanges && ! $range->isSingleAddress()) {
                throw ValidationException::withMessages([$field => 'Use a single IP address or hostname here. Put ranges under additional addresses.']);
            }
            if ($range->prefix < ($range->version === 4 ? 16 : 48)) {
                throw ValidationException::withMessages([$field => "{$address} is too large a range to trust. Use /16 or smaller for IPv4, /48 or smaller for IPv6."]);
            }
            if ($range->isLoopback()) {
                throw ValidationException::withMessages([$field => 'The loopback address cannot be a provider address.']);
            }

            return;
        }

        if (! HostResolver::isHostname($address)) {
            throw ValidationException::withMessages([$field => "\"{$address}\" is not a valid IP address, range or hostname."]);
        }

        if (app(HostResolver::class)->resolve($address) === []) {
            throw ValidationException::withMessages([$field => "Could not look up {$address}. Check the spelling, or enter the provider's IP addresses."]);
        }
    }
}
