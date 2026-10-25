<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;

/**
 * The outgoing (SMTP) mail server that admins configure from the portal.
 * Once saved, it replaces the MAIL_* values in .env.
 */
class MailSettings
{
    public const KEY = 'mail';

    public const ENCRYPTION = ['starttls', 'ssl', 'none'];

    /**
     * @return array{host: string, port: int, encryption: string, username: ?string, password: ?string, from_address: string, from_name: string}|null
     */
    public static function stored(): ?array
    {
        try {
            return Setting::read(self::KEY);
        } catch (QueryException) {
            // The settings table does not exist yet, e.g. during installation.
            return null;
        } catch (DecryptException) {
            // APP_KEY changed since the settings were saved; fall back to .env until they are saved again.
            return null;
        }
    }

    /**
     * Point Laravel's "smtp" mailer at the given settings and make it the default.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function apply(array $settings): void
    {
        self::configure($settings);

        // Drop any mailer already built from the old settings.
        Mail::purge('smtp');
    }

    /**
     * Called as the mail manager is first built, so stored settings are only
     * read from the database when the app actually sends mail.
     */
    public static function configureFromStored(): void
    {
        if ($settings = self::stored()) {
            self::configure($settings);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function configure(array $settings): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                ...config('mail.mailers.smtp'),
                'url' => null,
                'scheme' => $settings['encryption'] === 'ssl' ? 'smtps' : 'smtp',
                'host' => $settings['host'],
                'port' => (int) $settings['port'],
                'username' => $settings['username'] ?: null,
                'password' => $settings['password'] ?: null,
                'require_tls' => $settings['encryption'] === 'starttls',
                'auto_tls' => $settings['encryption'] !== 'none',
                'timeout' => 15,
            ],
            'mail.from' => [
                'address' => $settings['from_address'],
                'name' => $settings['from_name'],
            ],
        ]);
    }
}
