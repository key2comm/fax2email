<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailLog;
use App\Models\Setting;
use App\Support\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MailSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        Gate::authorize('manage-settings');

        $stored = MailSettings::stored();

        return Inertia::render('admin/MailSettings', [
            'settings' => [
                'host' => $stored['host'] ?? '',
                'port' => $stored['port'] ?? 587,
                'encryption' => $stored['encryption'] ?? 'starttls',
                'username' => $stored['username'] ?? '',
                'from_address' => $stored['from_address'] ?? '',
                'from_name' => $stored['from_name'] ?? config('app.name'),
            ],
            'hasPassword' => filled($stored['password'] ?? null),
            'isConfigured' => $stored !== null,
            'testRecipient' => $request->user()->email,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        Setting::write(MailSettings::KEY, $this->validated($request));

        return back()->with('status', 'Mail server settings saved.');
    }

    /**
     * Send a test email using the settings on the form, without saving them.
     */
    public function test(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        MailSettings::apply($this->validated($request));

        try {
            Mail::raw(
                'This is a test email from '.config('app.name').'. Your mail server settings are working.',
                fn ($message) => $message->to($request->user()->email)->subject('Test email from '.config('app.name')),
            );
        } catch (Throwable $e) {
            MailLog::recordFailure($e);

            return back()->withErrors(['test' => 'The test email could not be sent: '.$e->getMessage()]);
        }

        return back()->with('status', "Test email sent to {$request->user()->email}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(MailSettings::ENCRYPTION)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ]);

        // A blank password keeps the one already saved, so it never has to be sent back to the browser.
        if (blank($validated['password'] ?? null)) {
            $validated['password'] = MailSettings::stored()['password'] ?? null;
        }

        return $validated;
    }
}
