<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Contact::class);

        $search = trim((string) $request->query('search', ''));

        $contacts = Contact::where('account_id', $request->user()->account_id)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.mb_strtolower($search).'%';
                $digits = preg_replace('/\D/', '', $search);
                $query->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(company) like ?', [$like])
                    ->when($digits !== '', fn ($q) => $q->orWhere('fax_number', 'like', "%{$digits}%")));
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Contact $contact) => [
                ...$contact->only('id', 'name', 'company', 'fax_number', 'notes'),
                'formatted' => PhoneNumber::format($contact->fax_number),
            ]);

        return Inertia::render('contacts/Index', ['contacts' => $contacts, 'search' => $search]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Contact::class);

        $contact = Contact::create([
            ...$this->validated($request),
            'account_id' => $request->user()->account_id,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', "{$contact->name} added to the address book.");
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        $contact->update($this->validated($request, $contact));

        return back()->with('status', "{$contact->name} saved.");
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        Gate::authorize('delete', $contact);

        $contact->delete();

        return back()->with('status', "{$contact->name} removed from the address book.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Contact $contact = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'fax_number' => ['required', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $number = PhoneNumber::normalize($validated['fax_number']);
        if ($number === null) {
            throw ValidationException::withMessages(['fax_number' => 'Enter a fax number with its area code, e.g. 555 123 4567.']);
        }
        $duplicate = Contact::where('account_id', $request->user()->account_id)->where('fax_number', $number)
            ->when($contact, fn ($q) => $q->whereKeyNot($contact->id))->first();
        if ($duplicate) {
            throw ValidationException::withMessages(['fax_number' => "That number is already in the address book as {$duplicate->name}."]);
        }

        return [...$validated, 'fax_number' => $number];
    }
}
