<x-mail::message>
# You received a fax

The fax is attached as a PDF.

<x-mail::table>
| | |
|:--|:--|
| **From** | {{ $fax->caller_number ?: 'Unknown' }}@if ($fax->caller_name && $fax->caller_name !== $fax->caller_number) ({{ $fax->caller_name }})@endif |
@if ($fax->remote_station_id)
| **Sender's fax ID** | {{ $fax->remote_station_id }} |
@endif
| **To** | {{ $number?->formatted() ?? $fax->called_number }}@if ($number?->label) ({{ $number->label }})@endif |
| **Pages** | {{ $fax->pages }} |
| **Received** | {{ $fax->created_at->copy()->utc()->format('D, M j, Y g:i A') }} UTC |
</x-mail::table>

<x-mail::button :url="route('faxes.index')">
View faxes in the portal
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
