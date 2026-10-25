<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Account;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();

        Gate::authorize('viewAny', User::class);

        // Account owners are always pinned to their own account; staff may filter by any account.
        $account = $viewer->isStaff()
            ? Account::find($request->integer('account') ?: null)
            : $viewer->account;

        $users = User::query()
            ->visibleTo($viewer)
            ->when($account, fn ($query) => $query->where('account_id', $account->id))
            ->with('account:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'account' => $user->account?->only('id', 'name'),
                'assignable_roles' => collect($viewer->role->assignableRoles())
                    ->filter(fn (Role $role) => $role === $user->role || Gate::allows('changeRole', [$user, $role]))
                    ->map->value
                    ->values(),
                'can_delete' => Gate::allows('delete', $user),
            ]);

        $invitations = Invitation::query()
            ->pending()
            ->when(! $viewer->isStaff(), fn ($query) => $query->where('account_id', $viewer->account_id))
            ->when($account, fn ($query) => $query->where('account_id', $account->id))
            ->with('account:id,name')
            ->latest()
            ->get()
            ->map(fn (Invitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'account' => $invitation->account?->only('id', 'name'),
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'can_revoke' => Gate::allows('delete', $invitation),
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
            'invitations' => $invitations,
            'account' => $account?->only('id', 'name'),
            'accounts' => $viewer->isStaff() ? Account::orderBy('name')->get(['id', 'name']) : [],
            'roles' => collect(Role::cases())->map(fn (Role $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'is_staff' => $role->isStaff(),
            ]),
            'invitableRoles' => collect($viewer->role->assignableRoles())->map->value,
        ]);
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        Gate::authorize('changeRole', [$user, Role::from($validated['role'])]);

        $user->forceFill(['role' => $validated['role']])->save();

        return back()->with('status', "{$user->name} is now {$user->role->label()}.");
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $user->delete();

        return back()->with('status', "{$user->name} was removed.");
    }
}
