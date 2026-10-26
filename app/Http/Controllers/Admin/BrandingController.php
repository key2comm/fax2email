<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandingController extends Controller
{
    public function show(): Response
    {
        Gate::authorize('manage-settings');

        $branding = Branding::get();

        return Inertia::render('admin/Branding', [
            'values' => ['name' => $branding['name'], 'color' => $branding['color']],
            'defaultName' => Branding::DEFAULT_NAME,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        // PNG, JPEG or WebP only: SVG can carry scripts.
        $image = ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=16,min_height=16,max_width=4000,max_height=4000'];
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => $image,
            'logo_dark' => $image,
            'favicon' => $image,
            'remove' => ['array'],
            'remove.*' => [Rule::in(array_keys(Branding::ASSETS))],
        ], [
            '*.mimes' => 'Upload a PNG, JPG or WebP image. SVG files are not accepted because they can contain scripts.',
        ]);

        try {
            Branding::save(
                ['name' => trim((string) ($validated['name'] ?? '')), 'color' => isset($validated['color']) ? strtolower($validated['color']) : null],
                ['logo' => $request->file('logo'), 'logo-dark' => $request->file('logo_dark'), 'favicon' => $request->file('favicon')],
                $validated['remove'] ?? [],
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['logo' => $e->getMessage()]);
        }

        return back()->with('status', 'Branding saved.');
    }

    /**
     * Serve a branding image. Public, because the login page shows the logo.
     */
    public function asset(string $asset): StreamedResponse
    {
        abort_unless(isset(Branding::get()['assets'][$asset]) && Storage::disk('local')->exists(Branding::path($asset)), 404);

        return Storage::disk('local')->response(Branding::path($asset), "{$asset}.png", [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
