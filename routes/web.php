<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\FirewallController;
use App\Http\Controllers\Admin\MailLogController;
use App\Http\Controllers\Admin\MailSettingsController;
use App\Http\Controllers\Admin\SipTrunkController;
use App\Http\Controllers\FaxController;
use App\Http\Controllers\FaxNumberController;
use App\Http\Controllers\FreeswitchXmlController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\UserController;
use App\Models\Fax;
use App\Models\FaxNumber;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function (Request $request) {
        $faxes = Fax::visibleTo($request->user())->where('created_at', '>', now()->subDays(30));

        return Inertia::render('Dashboard', [
            'stats' => [
                'received' => (clone $faxes)->count(),
                'delivered' => (clone $faxes)->where('status', 'delivered')->count(),
                'numbers' => FaxNumber::visibleTo($request->user())->where('enabled', true)->count(),
            ],
            'recent' => Fax::visibleTo($request->user())->with('faxNumber:id,number,label')->latest('id')->limit(5)->get()
                ->map(fn (Fax $fax) => [
                    'id' => $fax->id,
                    'from' => $fax->caller_number ?: 'Unknown',
                    'to' => $fax->faxNumber?->formatted() ?? $fax->called_number,
                    'pages' => $fax->pages,
                    'status' => $fax->status,
                    'has_pdf' => $fax->pdf_path !== null,
                    'created_at' => $fax->created_at->toIso8601String(),
                ]),
        ]);
    })->name('dashboard');

    Route::resource('accounts', AccountController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('faxes', [FaxController::class, 'index'])->name('faxes.index');
    Route::get('faxes/{fax}/pdf', [FaxController::class, 'download'])->name('faxes.download');
    Route::post('faxes/{fax}/resend', [FaxController::class, 'resend'])->name('faxes.resend');
    Route::resource('numbers', FaxNumberController::class)->except('show');

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

    Route::resource('admin/trunks', SipTrunkController::class)->except('show')->parameters(['trunks' => 'trunk'])->names('admin.trunks');
});

// FreeSWITCH fetches its configuration here (mod_xml_curl). Machine-to-machine:
// no session or CSRF token; the controller checks the source address and credentials.
Route::post('freeswitch/xml', FreeswitchXmlController::class)
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('freeswitch.xml');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
