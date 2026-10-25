<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FaxNumber;
use App\Models\SipTrunk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FaxNumberController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FaxNumber::class);

        $numbers = FaxNumber::query()
            ->visibleTo($request->user())
            ->with('account:id,name')
            ->withCount(['faxes' => fn ($query) => $query->where('created_at', '>', now()->subDays(30))])
            ->orderBy('number')
            ->get()
            ->map(fn (FaxNumber $number) => [
                'id' => $number->id,
                'number' => $number->number,
                'formatted' => $number->formatted(),
                'label' => $number->label,
                'enabled' => $number->enabled,
                'account' => $number->account->only('id', 'name'),
                'recipients' => $number->recipients ?? [],
                'faxes_30d' => $number->faxes_count,
                'can_edit' => $request->user()->can('update', $number),
                'can_delete' => $request->user()->can('delete', $number),
            ]);

        return Inertia::render('numbers/Index', [
            'numbers' => $numbers,
            'canCreate' => $request->user()->can('create', FaxNumber::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', FaxNumber::class);

        return Inertia::render('numbers/Form', ['number' => null, 'accounts' => Account::orderBy('name')->get(['id', 'name']), 'trunks' => $this->trunks()]);
    }

    public function edit(Request $request, FaxNumber $number): Response
    {
        Gate::authorize('update', $number);

        return Inertia::render('numbers/Form', [
            'number' => [
                ...$number->only('id', 'number', 'label', 'enabled', 'account_id', 'outbound_trunk_id'),
                'recipients' => implode("\n", $number->recipients ?? []),
                'owner_emails' => $number->account->users()->where('role', 'account_owner')->pluck('email'),
            ],
            'accounts' => $request->user()->isStaff() ? Account::orderBy('name')->get(['id', 'name']) : [],
            'trunks' => $request->user()->isStaff() ? $this->trunks() : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', FaxNumber::class);

        $number = FaxNumber::create($this->validated($request, null, staff: true));

        return to_route('numbers.index')->with('status', "{$number->formatted()} added.");
    }

    public function update(Request $request, FaxNumber $number): RedirectResponse
    {
        Gate::authorize('update', $number);

        $number->update($this->validated($request, $number, staff: $request->user()->isStaff()));

        return to_route('numbers.index')->with('status', "{$number->formatted()} saved.");
    }

    public function destroy(FaxNumber $number): RedirectResponse
    {
        Gate::authorize('delete', $number);

        $number->delete();

        return to_route('numbers.index')->with('status', "{$number->formatted()} deleted. Calls to it will now be refused.");
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function trunks()
    {
        return SipTrunk::orderBy('name')->get(['id', 'name'])->map(fn (SipTrunk $t) => ['id' => $t->id, 'name' => $t->name]);
    }

    /**
     * Account Owners may only change the label and recipients.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?FaxNumber $number, bool $staff): array
    {
        $rules = [
            'label' => ['nullable', 'string', 'max:100'],
            'recipients' => ['nullable', 'string', 'max:5000'],
        ];
        if ($staff) {
            $rules += [
                'number' => ['required', 'string', 'max:40'],
                'account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')],
                'enabled' => ['required', 'boolean'],
                'outbound_trunk_id' => ['nullable', 'integer', Rule::exists(SipTrunk::class, 'id')],
            ];
        }
        $validated = $request->validate($rules);

        $recipients = preg_split('/[\s,;]+/', strtolower((string) ($validated['recipients'] ?? '')), flags: PREG_SPLIT_NO_EMPTY);
        foreach ($recipients as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['recipients' => "\"{$email}\" is not a valid email address."]);
            }
        }
        if (count($recipients) > 20) {
            throw ValidationException::withMessages(['recipients' => 'Up to 20 recipients per number.']);
        }
        $validated['recipients'] = array_values(array_unique($recipients)) ?: null;

        if ($staff) {
            $digits = FaxNumber::normalize($validated['number']);
            if (strlen($digits) < 3 || strlen($digits) > 20) {
                throw ValidationException::withMessages(['number' => 'Enter the fax number with its country code, e.g. +1 555 123 4567.']);
            }
            // North American 10-digit numbers are stored with the leading 1.
            $validated['number'] = strlen($digits) === 10 ? '1'.$digits : $digits;

            $clash = FaxNumber::where('number', $validated['number'])->when($number, fn ($query) => $query->whereKeyNot($number->id))->exists();
            if ($clash) {
                throw ValidationException::withMessages(['number' => 'That number is already set up.']);
            }
        }

        return $validated;
    }
}
