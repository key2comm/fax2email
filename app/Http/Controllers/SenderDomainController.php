<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\SenderDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SenderDomainController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // Accept "@example.com" or " Example.com " as well as "example.com".
        $request->merge(['domain' => strtolower(trim((string) $request->input('domain'), " \t@."))]);

        $validated = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')],
            'domain' => [
                'required', 'string', 'max:253',
                'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
                Rule::notIn(array_filter([...SenderDomain::PUBLIC_DOMAINS, config('fax2email.mail.domain')])),
                Rule::unique(SenderDomain::class),
            ],
        ], [
            'domain.regex' => 'Enter a domain like example.com.',
            'domain.not_in' => 'Anyone can get an address at this domain, so it cannot be allowed. Invite those people instead.',
            'domain.unique' => 'This domain is already allowed for an account.',
        ]);

        $account = Account::findOrFail($validated['account_id']);
        Gate::authorize('create', [SenderDomain::class, $account]);

        $account->senderDomains()->create([
            'domain' => $validated['domain'],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', "Anyone at {$validated['domain']} can now fax by email.");
    }

    public function destroy(SenderDomain $senderDomain): RedirectResponse
    {
        Gate::authorize('delete', $senderDomain);

        $senderDomain->delete();

        return back()->with('status', "{$senderDomain->domain} removed. Invited users there can still fax by email.");
    }
}
