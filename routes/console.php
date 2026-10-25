<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deletes expired rows from models that define prunable(), such as the mail log.
Schedule::command('model:prune')->daily();

// SIP trunks: registration status for the portal, and provider hostname changes.
Schedule::command('fax2email:trunk-status')->everyMinute()->withoutOverlapping();
Schedule::command('fax2email:trunk-resolve')->everyFiveMinutes()->withoutOverlapping();
