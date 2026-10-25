<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FaxEmail;
use App\Models\MailLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MailLogController extends Controller
{
    public function emailToFax(): Response
    {
        Gate::authorize('manage-settings');

        $emails = FaxEmail::latest('id')->paginate(50)->through(fn (FaxEmail $email) => [
            ...$email->only('id', 'from_address', 'recipient', 'subject', 'client_ip', 'spf', 'dkim_domain', 'status', 'reason', 'fax_id'),
            'created_at' => $email->created_at->toIso8601String(),
        ]);

        return Inertia::render('admin/EmailToFax', [
            'emails' => $emails,
            'domain' => config('fax2email.mail.domain') ?: null,
            'keepDays' => FaxEmail::KEEP_DAYS,
        ]);
    }

    public function index(Request $request): Response
    {
        Gate::authorize('manage-settings');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(MailLog::STATUSES)],
        ]);

        $logs = MailLog::query()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(fn ($query) => $query
                    ->whereRaw('lower(recipients) like ?', [$term])
                    ->orWhereRaw('lower(cc) like ?', [$term])
                    ->orWhereRaw('lower(bcc) like ?', [$term])
                    ->orWhereRaw('lower(subject) like ?', [$term]));
            })
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (MailLog $log) => [
                ...$log->only('id', 'sender', 'recipients', 'cc', 'bcc', 'subject', 'attachments', 'status', 'error', 'message_id'),
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('admin/MailLog', [
            'logs' => $logs,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'keepDays' => MailLog::KEEP_DAYS,
        ]);
    }
}
