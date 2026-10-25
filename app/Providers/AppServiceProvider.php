<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\MailLog;
use App\Models\User;
use App\Support\MailSettings;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        Gate::define('manage-settings', fn (User $user) => $user->role === Role::Admin);

        $this->app->resolving('mail.manager', fn () => MailSettings::configureFromStored());

        Event::listen(MessageSending::class, fn (MessageSending $event) => MailLog::recordSending($event->message));
        Event::listen(MessageSent::class, fn (MessageSent $event) => MailLog::recordSent($event->sent->getSymfonySentMessage()));
    }
}
