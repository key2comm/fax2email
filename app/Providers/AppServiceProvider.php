<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\FaxNumber;
use App\Models\MailLog;
use App\Models\User;
use App\Support\Branding;
use App\Support\MailSettings;
use Illuminate\Auth\Events\Failed;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        config(['app.name' => Branding::name()]);

        Gate::define('manage-settings', fn (User $user) => $user->role === Role::Admin);
        // Sending needs an account with a number to send from.
        Gate::define('send-fax', fn (User $user) => $user->account_id !== null
            && FaxNumber::where('account_id', $user->account_id)->where('enabled', true)->exists());

        $this->app->resolving('mail.manager', fn () => MailSettings::configureFromStored());

        Event::listen(MessageSending::class, fn (MessageSending $event) => MailLog::recordSending($event->message));
        Event::listen(MessageSent::class, fn (MessageSent $event) => MailLog::recordSent($event->sent->getSymfonySentMessage()));

        // fail2ban's f2e_portal filter matches "Failed portal login from <IP> for ".
        // The IP comes first and the (attacker-controlled) email is cleaned, so a
        // crafted email cannot make fail2ban ban someone else's IP.
        Event::listen(Failed::class, function (Failed $event) {
            $email = mb_substr(preg_replace('/[^\x21-\x7E]/', '?', (string) ($event->credentials['email'] ?? '')), 0, 100);
            Log::channel('auth_failures')->warning('Failed portal login from '.request()->ip()." for {$email}");
        });
    }
}
