<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\HostResolver;
use App\Support\Https;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class HttpsController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('manage-settings');

        $settings = Https::settings();
        $domain = $settings['domain'] ?: ($request->getHost() !== '' && ! filter_var($request->getHost(), FILTER_VALIDATE_IP) ? $request->getHost() : null);

        return Inertia::render('admin/Https', [
            'settings' => [...$settings, 'domain' => $domain, 'email' => $settings['email'] ?? $request->user()->email],
            'status' => Https::status(),
            'nextRenewal' => Https::nextRenewal(),
            'renewDays' => Https::RENEW_DAYS,
            // Where the domain points now, so admins can spot DNS that isn't set up yet.
            'resolvesTo' => $domain ? app(HostResolver::class)->resolve($domain) : [],
            'serverAddress' => $request->server('SERVER_ADDR'),
        ]);
    }

    /**
     * Get a free certificate from Let's Encrypt.
     */
    public function letsEncrypt(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'email' => ['required', 'email', 'max:255'],
            'agree' => ['accepted'],
            'redirect' => ['required', 'boolean'],
        ], ['agree.accepted' => "You need to agree to Let's Encrypt's terms of service."]);

        $domain = $this->checkDomain($validated['domain']);
        if (app(HostResolver::class)->resolve($domain) === []) {
            throw ValidationException::withMessages(['domain' => "{$domain} does not resolve yet. Create its DNS A record pointing at this server, then try again."]);
        }

        Https::save(['mode' => 'letsencrypt', 'domain' => $domain, 'email' => strtolower($validated['email']), 'redirect' => (bool) $validated['redirect']]);

        return back()->with('status', "Requesting a certificate for {$domain}. This usually takes under a minute.");
    }

    /**
     * Install a certificate the admin already has.
     */
    public function custom(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'certificate' => ['required', 'string', 'max:65535'],
            'private_key' => ['required', 'string', 'max:65535'],
            'redirect' => ['required', 'boolean'],
        ]);
        $domain = $this->checkDomain($validated['domain']);

        try {
            $info = Https::checkCertificate($validated['certificate'], $validated['private_key']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['certificate' => $e->getMessage()]);
        }
        if (! Https::covers($info['names'], $domain)) {
            throw ValidationException::withMessages(['certificate' => 'This certificate is for '.implode(', ', $info['names'])." and does not cover {$domain}."]);
        }

        // Handed to the apply service, which moves them somewhere only root can read.
        if (! is_dir(Https::dir())) {
            mkdir(Https::dir(), 0770, true);
        }
        file_put_contents(Https::dir().'/custom.crt', trim($validated['certificate'])."\n");
        file_put_contents(Https::dir().'/custom.key', trim($validated['private_key'])."\n");
        chmod(Https::dir().'/custom.key', 0600);

        Https::save(['mode' => 'custom', 'domain' => $domain, 'email' => null, 'redirect' => (bool) $validated['redirect']]);

        return back()->with('status', "Installing the certificate for {$domain}.");
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $validated = $request->validate([
            'redirect' => ['sometimes', 'boolean'],
            'auto_renew' => ['sometimes', 'boolean'],
            'renew_days' => ['sometimes', 'integer', Rule::in(Https::RENEW_DAYS)],
        ]);
        Https::save(array_map(fn ($v) => is_bool($v) || $v === '0' || $v === '1' ? (bool) $v : (int) $v, $validated));

        return back()->with('status', 'HTTPS settings saved.');
    }

    /**
     * Replace the Let's Encrypt certificate now, whatever its age.
     */
    public function renew(): RedirectResponse
    {
        Gate::authorize('manage-settings');
        abort_unless(Https::settings()['mode'] === 'letsencrypt', 404);

        Https::save(['force_renew' => true]);

        return back()->with('status', 'Renewing the certificate now.');
    }

    public function disable(): RedirectResponse
    {
        Gate::authorize('manage-settings');

        Https::save([...Https::settings(), 'mode' => 'none']);

        return back()->with('status', 'HTTPS is being turned off. The portal will be served over plain HTTP.');
    }

    private function checkDomain(string $value): string
    {
        $domain = strtolower(trim($value));
        if (! HostResolver::isHostname($domain) || ! str_contains($domain, '.')) {
            throw ValidationException::withMessages(['domain' => 'Enter the portal\'s domain name, e.g. fax.example.com. Certificates cannot be issued for IP addresses.']);
        }

        return $domain;
    }
}
