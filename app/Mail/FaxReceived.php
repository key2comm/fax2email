<?php

namespace App\Mail;

use App\Models\Fax;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class FaxReceived extends Mailable
{
    use Queueable;

    public function __construct(public Fax $fax) {}

    public function envelope(): Envelope
    {
        $from = $this->fax->caller_number ?: $this->fax->remote_station_id ?: 'unknown sender';
        $pages = $this->fax->pages === 1 ? '1 page' : "{$this->fax->pages} pages";

        return new Envelope(subject: "Fax from {$from} ({$pages})");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.fax-received', with: [
            'fax' => $this->fax,
            'number' => $this->fax->faxNumber,
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $name = sprintf('fax-%s-%s.pdf', $this->fax->created_at->format('Y-m-d-His'), preg_replace('/\W/', '', (string) $this->fax->caller_number) ?: 'unknown');

        return [Attachment::fromStorageDisk('local', $this->fax->pdf_path)->as($name)->withMime('application/pdf')];
    }
}
