<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\FaxNumber;
use App\Support\Fax\FaxOutbox;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SendFaxController extends Controller
{
    public const MAX_FILES = 10;

    /** Per file, in kilobytes. */
    public const MAX_FILE_KB = 20480;

    public function create(Request $request): Response
    {
        Gate::authorize('send-fax');
        $account = $request->user()->account_id;

        return Inertia::render('faxes/Send', [
            'contacts' => Contact::where('account_id', $account)->orderBy('name')->get()
                ->map(fn (Contact $c) => ['id' => $c->id, 'name' => $c->name, 'company' => $c->company, 'fax_number' => $c->fax_number, 'formatted' => PhoneNumber::format($c->fax_number)]),
            'numbers' => FaxNumber::where('account_id', $account)->where('enabled', true)->orderBy('number')->get()
                ->map(fn (FaxNumber $n) => ['id' => $n->id, 'label' => $n->formatted().($n->label ? " ({$n->label})" : '')]),
            'contactId' => $request->integer('contact') ?: null,
            'maxFiles' => self::MAX_FILES,
            'maxFileMb' => intdiv(self::MAX_FILE_KB, 1024),
        ]);
    }

    public function store(Request $request, FaxOutbox $outbox): RedirectResponse
    {
        Gate::authorize('send-fax');
        $user = $request->user();

        $validated = $request->validate([
            'to' => ['required', 'string', 'max:40'],
            'to_name' => ['nullable', 'string', 'max:255'],
            'from_number_id' => ['required', 'integer', Rule::exists(FaxNumber::class, 'id')->where('account_id', $user->account_id)->where('enabled', true)],
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'mimes:pdf', 'max:'.self::MAX_FILE_KB],
        ], [
            'files.required' => 'Attach at least one PDF.',
            'files.*.mimes' => 'Only PDF files can be faxed.',
        ]);

        $destination = PhoneNumber::normalize($validated['to']);
        if ($destination === null) {
            throw ValidationException::withMessages(['to' => 'Enter the fax number with its area code, e.g. 555 123 4567.']);
        }

        // Store each upload; FaxOutbox moves them under the fax and joins them in this order.
        $paths = array_map(fn ($file) => $file->store('faxes/incoming-uploads', 'local'), $validated['files']);

        $fax = $outbox->queue(
            FaxNumber::findOrFail($validated['from_number_id']),
            $destination,
            ($validated['to_name'] ?? null) ?: Contact::where('account_id', $user->account_id)->where('fax_number', $destination)->value('name'),
            $paths,
            $user,
            $user->email,
            'portal',
        );

        return to_route('faxes.index', ['direction' => 'outbound'])
            ->with('status', 'Fax to '.($fax->destination_name ?: PhoneNumber::format($destination)).' queued. You will get an email when it has been sent.');
    }
}
