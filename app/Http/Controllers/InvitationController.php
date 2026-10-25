<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Account;
use App\Models\Invitation;
use App\Models\MailLog;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class InvitationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'role' => ['required', Rule::enum(Role::class)],
            'account_id' => ['nullable', 'integer', Rule::exists(Account::class, 'id')],
        ], [
            'email.unique' => 'A user with this email address already exists.',
        ]);

        $role = Role::from($validated['role']);
        $account = isset($validated['account_id']) ? Account::find($validated['account_id']) : null;

        Gate::authorize('invite', [User::class, $role, $account]);

        // Re-inviting someone replaces any invitation they have not used yet.
        Invitation::query()->pending()->where('email', $validated['email'])->delete();

        [$invitation, $token] = Invitation::issue([
            'email' => $validated['email'],
            'role' => $role,
            'account_id' => $account?->id,
            'invited_by' => $request->user()->id,
        ]);

        try {
            Notification::route('mail', $invitation->email)->notify(new UserInvited($invitation, $token));
        } catch (TransportExceptionInterface $e) {
            MailLog::recordFailure($e);
            $invitation->delete();

            return back()->withErrors(['email' => 'The invitation email could not be sent. Check the mail server settings. ('.$e->getMessage().')']);
        }

        return back();
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        Gate::authorize('delete', $invitation);

        $invitation->delete();

        return back();
    }
}
