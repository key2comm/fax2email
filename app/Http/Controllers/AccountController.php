<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Account::class);

        $accounts = Account::query()
            ->withCount(['users', 'owners'])
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'is_active' => $account->is_active,
                'users_count' => $account->users_count,
                'owners_count' => $account->owners_count,
            ]);

        return Inertia::render('accounts/Index', [
            'accounts' => $accounts,
            'canDelete' => $request->user()->role === Role::Admin,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Account::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $account = Account::create($validated);

        return to_route('users.index', ['account' => $account->id]);
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        Gate::authorize('update', $account);

        $account->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]));

        return back();
    }

    public function destroy(Account $account): RedirectResponse
    {
        Gate::authorize('delete', $account);

        $account->delete();

        return to_route('accounts.index');
    }
}
