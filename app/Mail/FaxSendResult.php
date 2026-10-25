<?php

namespace App\Mail;

use App\Models\Fax;
use App\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the sender whether their fax went through, with a copy of what was sent.
 */
class FaxSendResult extends Mailable
{
    use Queueable;

    public function __construct(public Fax $fax) {}

    public function envelope(): Envelope
    {
        $to = $this->fax->destination_name ?: PhoneNumber::format($this->fax->destination_number);
        $pages = $this->fax->pages === 1 ? '1 page' : "{$this->fax->pages} pages";

        return new Envelope(subject: $this->fax->status === 'sent'
            ? "Fax sent to {$to} ({$pages})"
            : "Fax to {$to} failed");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.fax-send-result', with: [
            'fax' => $this->fax,
            'to' => PhoneNumber::format($this->fax->destination_number),
            'from' => PhoneNumber::format($this->fax->caller_number),
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if (! $this->fax->pdf_path) {
            return [];
        }

        return [Attachment::fromStorageDisk('local', $this->fax->pdf_path)
            ->as(sprintf('fax-to-%s-%s.pdf', $this->fax->destination_number, $this->fax->created_at->format('Y-m-d-His')))
            ->withMime('application/pdf')];
    }
}
