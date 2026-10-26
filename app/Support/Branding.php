<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The app's name, logo, favicon and brand colour, set by Admins under
 * Settings > Branding. They brand the website only; emails are not changed. Uploaded images are decoded and re-saved as PNG, so
 * what the portal serves is always a plain image, whatever was uploaded.
 */
class Branding
{
    public const KEY = 'branding';

    public const DEFAULT_NAME = 'Fax2Email';

    /** Uploads that can be set, and the largest size each is scaled down to (width x height). */
    public const ASSETS = [
        'logo' => [600, 160],
        'logo-dark' => [600, 160],
        'favicon' => [256, 256],
    ];

    /**
     * @return array{name: ?string, color: ?string, assets: array<string, int>}
     */
    public static function get(): array
    {
        // Cached on the application (one per request or job), never in a static that outlives it.
        if (! app()->bound('fax2email.branding')) {
            try {
                $stored = Setting::read(self::KEY) ?? [];
            } catch (Throwable) {
                // No database yet (installing, building a release): use the defaults.
                $stored = [];
            }
            app()->instance('fax2email.branding', ['name' => $stored['name'] ?? null, 'color' => $stored['color'] ?? null, 'assets' => $stored['assets'] ?? []]);
        }

        return app('fax2email.branding');
    }

    public static function name(): string
    {
        return self::get()['name'] ?: self::DEFAULT_NAME;
    }

    /**
     * Everything the pages need: the name, image URLs (versioned so browsers
     * pick up a new upload straight away) and the colour.
     *
     * @return array{name: string, color: ?string, logo: ?string, logo_dark: ?string, favicon: ?string}
     */
    public static function forPages(): array
    {
        $b = self::get();
        $url = fn (string $asset) => isset($b['assets'][$asset]) ? route('branding.asset', $asset).'?v='.$b['assets'][$asset] : null;

        return [
            'name' => self::name(),
            'color' => $b['color'],
            'logo' => $url('logo'),
            'logo_dark' => $url('logo-dark'),
            'favicon' => $url('favicon'),
        ];
    }

    /**
     * @param  array{name: ?string, color: ?string}  $values
     * @param  array<string, UploadedFile|null>  $uploads
     * @param  list<string>  $remove
     */
    public static function save(array $values, array $uploads, array $remove): void
    {
        $assets = self::get()['assets'];

        foreach ($remove as $asset) {
            Storage::disk('local')->delete(self::path($asset));
            unset($assets[$asset]);
        }
        foreach ($uploads as $asset => $file) {
            if ($file) {
                self::storeImage($asset, $file);
                $assets[$asset] = time();
            }
        }

        Setting::write(self::KEY, ['name' => $values['name'] ?: null, 'color' => $values['color'] ?: null, 'assets' => $assets]);
        app()->forgetInstance('fax2email.branding');

    }

    public static function path(string $asset): string
    {
        return "branding/{$asset}.png";
    }

    /**
     * Text on the brand colour: white on dark colours, near-black on light ones.
     */
    public static function foreground(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.6 ? '#1d273b' : '#ffffff';
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        return array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    }

    /**
     * Decode the upload and save a fresh PNG, scaled down to fit the asset's size.
     */
    private static function storeImage(string $asset, UploadedFile $file): void
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($image === false) {
            throw new RuntimeException('The image could not be read.');
        }

        [$maxWidth, $maxHeight] = self::ASSETS[$asset];
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagepng($canvas, null, 9);
        Storage::disk('local')->put(self::path($asset), (string) ob_get_clean());
    }
}
