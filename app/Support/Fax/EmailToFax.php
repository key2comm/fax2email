<?php

namespace App\Support\Fax;

use App\Http\Controllers\SendFaxController;
use App\Mail\FaxEmailRejected;
use App\Models\Contact;
use App\Models\FaxEmail;
use App\Models\FaxNumber;
use App\Models\SenderDomain;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SPFLib\Check\Environment;
use SPFLib\Check\Result;
use SPFLib\Checker;
use SPFLib\DNS\Resolver;
use Throwable;
use ZBateson\MailMimeParser\Message;

/**
 * Turns an email sent to <number>@<fax domain> into an outgoing fax.
 *
 * The From address is easy to forge, so a message is only accepted when the
 * sender is proven: SPF passes for the envelope sender, or OpenDKIM (on this
 * server) reports a passing DKIM signature, and that domain matches the From
 * address's domain (DMARC-style alignment). The sender must also be a portal
 * user, or at one of an account's sender domains, and that account must have
 * a fax number. Unproven mail is dropped silently, so
 * forged messages never cause emails to the innocent address they claimed.
 */
class EmailToFax
{
    public function __construct(private ?Resolver $dns = null) {}

    public function handle(string $raw): FaxEmail
    {
        $message = Message::from($raw, false);
        $domain = config('fax2email.mail.domain');

        // Postfix's virtual delivery adds Delivered-To and Return-Path above everything the sender wrote.
        $recipient = strtolower(trim((string) $message->getHeaderValue('Delivered-To')));
        $envelopeFrom = strtolower(trim((string) $message->getHeaderValue('Return-Path'), "<> \t"));
        $from = strtolower(trim((string) $message->getHeader('From')?->getEmail()));
        [$clientIp, $helo] = $this->connectingClient($message);

        $log = new FaxEmail([
            'from_address' => mb_substr($from, 0, 255) ?: null,
            'recipient' => mb_substr($recipient, 0, 255) ?: null,
            'subject' => mb_substr((string) $message->getHeaderValue('Subject'), 0, 255) ?: null,
            'client_ip' => $clientIp,
        ]);

        if (! $domain || ! preg_match('/^(\d{7,15})@'.preg_quote($domain, '/').'$/', $recipient, $m)) {
            return $this->reject($log, 'Not addressed to <fax number>@'.($domain ?: '(email-to-fax is off)').'.');
        }
        $destination = PhoneNumber::normalize($m[1]);

        // Prove the sender.
        $fromDomain = Str::after($from, '@');
        $spf = $this->spf($clientIp, $helo, $envelopeFrom);
        $dkimDomains = $this->passingDkimDomains($message);
        $log->spf = $spf;
        $log->dkim_domain = $dkimDomains[0] ?? null;

        $spfAligned = $spf === Result::CODE_PASS && $this->aligned($fromDomain, Str::after($envelopeFrom, '@'));
        $dkimAligned = collect($dkimDomains)->contains(fn ($d) => $this->aligned($fromDomain, $d));
        if ($from === '' || (! $spfAligned && ! $dkimAligned)) {
            return $this->reject($log, "Sender not verified (SPF {$spf}, DKIM ".($dkimDomains ? 'for '.implode(', ', $dkimDomains) : 'none').'); dropped without reply.');
        }

        // From here on the sender is genuine, so it is safe to tell them what went wrong.
        // A portal user sends for their own account; anyone else needs an account that allows their domain.
        $user = User::whereRaw('lower(email) = ?', [$from])->first();
        $accountId = $user?->account_id ?? SenderDomain::where('domain', $fromDomain)->value('account_id');
        $number = $accountId
            ? FaxNumber::where('account_id', $accountId)->where('enabled', true)->orderBy('id')->first()
            : null;
        if (! $number) {
            return $this->reject($log, 'The sender is not a portal user or at an allowed domain of an account with a fax number.', notify: $from);
        }

        $pdfs = collect($message->getAllAttachmentParts())->filter(fn ($part) => $part->getContentType() === 'application/pdf'
            || str_ends_with(strtolower((string) $part->getFilename()), '.pdf'))->values();
        if ($pdfs->isEmpty()) {
            return $this->reject($log, 'No PDF was attached.', notify: $from);
        }
        if ($pdfs->count() > SendFaxController::MAX_FILES) {
            return $this->reject($log, 'Too many attachments (up to '.SendFaxController::MAX_FILES.' PDFs per fax).', notify: $from);
        }

        $disk = Storage::disk('local');
        $paths = [];
        foreach ($pdfs as $part) {
            $content = (string) $part->getBinaryContentStream()?->getContents();
            if (strlen($content) > SendFaxController::MAX_FILE_KB * 1024) {
                array_map(fn ($p) => $disk->delete($p), $paths);

                return $this->reject($log, ($part->getFilename() ?: 'An attachment').' is larger than '.intdiv(SendFaxController::MAX_FILE_KB, 1024).' MB.', notify: $from);
            }
            $path = 'faxes/incoming-uploads/'.Str::uuid().'.pdf';
            $disk->put($path, $content);
            $paths[] = $path;
        }

        $fax = app(FaxOutbox::class)->queue(
            $number,
            $destination,
            Contact::where('account_id', $accountId)->where('fax_number', $destination)->value('name'),
            $paths,
            $user?->account_id ? $user : null,
            $from,
            'email',
        );

        $log->fill(['status' => 'accepted', 'fax_id' => $fax->id])->save();

        return $log;
    }

    /**
     * The client that delivered the message to this server, from the Received
     * header Postfix added (the topmost one; anything below is the sender's word).
     *
     * @return array{0: ?string, 1: string}
     */
    private function connectingClient(Message $message): array
    {
        $received = $message->getHeader('Received')?->getRawValue() ?? '';
        $ip = preg_match('/\[(?:IPv6:)?([0-9a-fA-F.:]+)\]/', $received, $m) && filter_var($m[1], FILTER_VALIDATE_IP) ? $m[1] : null;
        $helo = preg_match('/^\s*from\s+(\S+)/i', $received, $m) ? $m[1] : '';

        return [$ip, $helo];
    }

    private function spf(?string $ip, string $helo, string $envelopeFrom): string
    {
        if ($ip === null) {
            return 'none';
        }

        try {
            $checker = new Checker($this->dns);
            $result = $checker->check(new Environment($ip, $helo, $envelopeFrom), Checker::FLAG_CHECK_MAILFROADDRESS);

            return $result->getCode();
        } catch (Throwable) {
            return Result::CODE_ERROR_TEMPORARY;
        }
    }

    /**
     * Domains with a passing DKIM signature, as reported by this server's
     * OpenDKIM. Only its own header counts: it is identified by a secret
     * authserv-id, so a header the sender added cannot be mistaken for it.
     *
     * @return list<string>
     */
    private function passingDkimDomains(Message $message): array
    {
        $id = config('fax2email.mail.authserv_id');
        if (! $id) {
            return [];
        }

        foreach ($message->getAllHeadersByName('Authentication-Results') as $header) {
            $value = preg_replace('/\s+/', ' ', $header->getRawValue());
            if (! str_starts_with(trim($value), $id.';') && ! str_starts_with(trim($value), $id.' ')) {
                continue;
            }
            // Drop comments like "(2048-bit key; unprotected)" first: they can contain semicolons.
            $value = preg_replace('/\([^()]*\)/', '', $value);
            preg_match_all('/dkim=pass\b[^;]*?header\.d=([a-z0-9.-]+)/i', $value, $m);

            return array_values(array_unique(array_map('strtolower', $m[1])));
        }

        return [];
    }

    /**
     * DMARC-style relaxed alignment: the same domain, or one a subdomain of the other.
     */
    private function aligned(string $fromDomain, string $authDomain): bool
    {
        $fromDomain = strtolower($fromDomain);
        $authDomain = strtolower($authDomain);

        return $fromDomain !== '' && $authDomain !== '' && ($fromDomain === $authDomain
            || str_ends_with($fromDomain, '.'.$authDomain)
            || str_ends_with($authDomain, '.'.$fromDomain));
    }

    private function reject(FaxEmail $log, string $reason, ?string $notify = null): FaxEmail
    {
        $log->fill(['status' => 'rejected', 'reason' => mb_substr($reason, 0, 255)])->save();

        if ($notify) {
            try {
                Mail::to($notify)->send(new FaxEmailRejected($log));
            } catch (Throwable) {
                // The rejection is still in the log for admins.
            }
        }

        return $log;
    }
}
