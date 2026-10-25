<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessReceivedFax;
use App\Mail\FaxReceived;
use App\Models\Account;
use App\Models\Fax;
use App\Models\FaxNumber;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FaxController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        Gate::authorize('viewAny', Fax::class);

        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(['inbound', 'outbound'])],
            'status' => ['nullable', Rule::in(Fax::STATUSES)],
            'number' => ['nullable', 'integer'],
            'account' => ['nullable', 'integer'],
        ]);

        $direction = $filters['direction'] ?? 'inbound';

        $faxes = Fax::query()
            ->visibleTo($viewer)
            ->where('direction', $direction)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['number'] ?? null, fn ($query, $number) => $query->where('fax_number_id', $number))
            ->when($viewer->isStaff() && ($filters['account'] ?? null), fn ($query) => $query->where('account_id', $filters['account']))
            ->with(['faxNumber:id,number,label', 'account:id,name', 'user:id,name'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Fax $fax) => [
                'id' => $fax->id,
                'status' => $fax->status,
                'caller_number' => $fax->caller_number,
                'caller_name' => $fax->caller_name,
                'remote_station_id' => $fax->remote_station_id,
                'to' => $fax->isOutbound() ? PhoneNumber::format($fax->destination_number) : ($fax->faxNumber?->formatted() ?? $fax->called_number),
                'to_label' => $fax->isOutbound() ? $fax->destination_name : $fax->faxNumber?->label,
                'from' => $fax->isOutbound() ? $fax->faxNumber?->formatted() : $fax->caller_number,
                'sent_by' => $fax->user?->name ?? $fax->sender_email,
                'source' => $fax->source,
                'attempts' => $fax->attempts,
                'next_attempt_at' => $fax->next_attempt_at?->toIso8601String(),
                'account' => $fax->account?->name,
                'pages' => $fax->pages,
                'result_text' => $fax->result_text,
                'emailed_at' => $fax->emailed_at?->toIso8601String(),
                'email_error' => $fax->email_error,
                'has_pdf' => $fax->pdf_path !== null,
                'can_resend' => ! $fax->isOutbound() && $viewer->can('resend', $fax),
                'created_at' => $fax->created_at->toIso8601String(),
            ]);

        return Inertia::render('faxes/Index', [
            'faxes' => $faxes,
            'direction' => $direction,
            'filters' => ['status' => $filters['status'] ?? '', 'number' => $filters['number'] ?? '', 'account' => $filters['account'] ?? ''],
            'numbers' => FaxNumber::visibleTo($viewer)->orderBy('number')->get()->map(fn (FaxNumber $n) => ['id' => $n->id, 'label' => $n->formatted().($n->label ? " ({$n->label})" : '')]),
            'accounts' => $viewer->isStaff() ? Account::orderBy('name')->get(['id', 'name']) : [],
            'keepDays' => Fax::KEEP_DAYS,
        ]);
    }

    public function download(Fax $fax): StreamedResponse
    {
        Gate::authorize('view', $fax);
        abort_unless($fax->pdf_path && Storage::disk('local')->exists($fax->pdf_path), 404, 'The PDF for this fax is not available.');

        return Storage::disk('local')->download($fax->pdf_path, sprintf('fax-%d-%s.pdf', $fax->id, $fax->created_at->format('Y-m-d')));
    }

    public function resend(Fax $fax): RedirectResponse
    {
        Gate::authorize('resend', $fax);
        abort_if($fax->isOutbound(), 404);

        if (! $fax->pdf_path) {
            // Not processed yet (or processing failed): run the whole job again.
            ProcessReceivedFax::dispatch($fax->id);

            return back()->with('status', 'Fax queued for processing again.');
        }

        $recipients = $fax->faxNumber?->deliveryAddresses() ?? [];
        if ($recipients === []) {
            return back()->withErrors(['fax' => 'This number has no recipients and its account has no owners to send to.']);
        }

        try {
            Mail::to($recipients)->send(new FaxReceived($fax));
        } catch (Throwable $e) {
            $fax->update(['email_error' => $e->getMessage()]);

            return back()->withErrors(['fax' => 'The email could not be sent: '.$e->getMessage()]);
        }

        $fax->forceFill(['status' => 'delivered', 'emailed_at' => now(), 'email_error' => null])->save();

        return back()->with('status', 'Fax emailed to '.implode(', ', $recipients).'.');
    }
}
