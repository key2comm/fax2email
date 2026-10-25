<x-mail::message>
# Your fax was not sent

We received your email to **{{ $email->recipient }}** but could not fax it.

**Reason:** {{ $email->reason }}

To send a fax by email, email one or more PDF files from the address you use to log in to the portal, to the fax number followed by **{{ '@'.config('fax2email.mail.domain') }}**, for example 15551234567{{ '@'.config('fax2email.mail.domain') }}.

<x-mail::button :url="route('faxes.send')">
Send from the portal instead
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
