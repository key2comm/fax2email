<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\FirewallController;
use App\Http\Controllers\Admin\MailLogController;
use App\Http\Controllers\Admin\MailSettingsController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::resource('accounts', AccountController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    Route::get('admin/mail', [MailSettingsController::class, 'edit'])->name('admin.mail.edit');
    Route::put('admin/mail', [MailSettingsController::class, 'update'])->name('admin.mail.update');
    Route::post('admin/mail/test', [MailSettingsController::class, 'test'])->name('admin.mail.test');
    Route::get('admin/mail/log', [MailLogController::class, 'index'])->name('admin.mail.log');

    Route::get('admin/firewall', [FirewallController::class, 'blocked'])->name('admin.firewall.blocked');
    Route::get('admin/firewall/whitelist', [FirewallController::class, 'whitelist'])->name('admin.firewall.whitelist');
    Route::get('admin/firewall/settings', [FirewallController::class, 'settings'])->name('admin.firewall.settings');
    Route::get('admin/firewall/activity', [FirewallController::class, 'activity'])->name('admin.firewall.activity');
    Route::post('admin/firewall/rules', [FirewallController::class, 'store'])->name('admin.firewall.rules.store');
    Route::delete('admin/firewall/rules/{rule}', [FirewallController::class, 'destroy'])->name('admin.firewall.rules.destroy');
    Route::put('admin/firewall/settings', [FirewallController::class, 'updateSettings'])->name('admin.firewall.settings.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
