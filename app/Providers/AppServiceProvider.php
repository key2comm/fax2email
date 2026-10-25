<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Support\MailSettings;
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
    }
}
