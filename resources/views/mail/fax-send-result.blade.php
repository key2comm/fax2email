<x-mail::message>
@if ($fax->status === 'sent')
# Your fax was sent

The fax to **{{ $fax->destination_name ? $fax->destination_name.' ('.$to.')' : $to }}** went through. A copy of what was sent is attached.
@else
# Your fax could not be sent

The fax to **{{ $fax->destination_name ? $fax->destination_name.' ('.$to.')' : $to }}** failed after {{ $fax->attempts }} {{ $fax->attempts === 1 ? 'attempt' : 'attempts' }}.

**Reason:** {{ $fax->result_text ?: 'Unknown' }}
@endif

<x-mail::table>
| | |
|:--|:--|
| **To** | {{ $to }} |
| **From** | {{ $from }} |
| **Pages** | {{ $fax->pages }} |
@if ($fax->remote_station_id)
| **Receiver's fax ID** | {{ $fax->remote_station_id }} |
@endif
| **Attempts** | {{ $fax->attempts }} |
</x-mail::table>

<x-mail::button :url="route('faxes.index', ['direction' => 'outbound'])">
View sent faxes
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
