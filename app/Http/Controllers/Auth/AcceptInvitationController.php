<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    public function create(string $token): Response
    {
        $invitation = Invitation::findPendingByToken($token);

        abort_if($invitation === null, 404, 'This invitation is invalid or has expired.');

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'email' => $invitation->email,
            'role' => $invitation->role->label(),
            'accountName' => $invitation->account?->name,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($request, $token) {
            $invitation = Invitation::findPendingByToken($token);

            abort_if($invitation === null, 404, 'This invitation is invalid or has expired.');

            if (User::where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages([
                    'name' => 'An account with this email address already exists. Please log in instead.',
                ]);
            }

            $user = new User([
                'name' => $request->name,
                'email' => $invitation->email,
                'password' => $request->password,
            ]);
            // The invitation link was delivered to this address, which proves the user owns it.
            $user->forceFill([
                'role' => $invitation->role,
                'account_id' => $invitation->account_id,
                'email_verified_at' => now(),
            ])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return to_route('dashboard');
    }
}
