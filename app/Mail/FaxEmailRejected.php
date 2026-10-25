<?php

namespace App\Mail;

use App\Models\FaxEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent only to verified senders whose fax email could not be faxed.
 */
class FaxEmailRejected extends Mailable
{
    use Queueable;

    public function __construct(public FaxEmail $faxEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your fax was not sent'.($this->faxEmail->subject ? ": {$this->faxEmail->subject}" : ''));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.fax-email-rejected', with: ['email' => $this->faxEmail]);
    }
}
