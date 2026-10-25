<?php

namespace App\Support\Fax;

use App\Jobs\ProcessReceivedFax;
use App\Models\Fax;
use App\Models\FaxNumber;
use Illuminate\Support\Str;

/**
 * Turns what FreeSWITCH leaves behind after a fax call into Fax records and
 * queues them for delivery. Normally driven by the "fax received" event; the
 * sweep picks up any file whose event was missed (e.g. the listener restarted).
 */
class FaxInbox
{
    /**
     * Handle a spandsp::rxfaxresult event.
     *
     * @param  array<string, string>  $event
     */
    public function recordEvent(array $event): ?Fax
    {
        $uuid = $event['Unique-ID'] ?? null;
        $file = $event['variable_fax_file'] ?? null;
        if (! $uuid || ! Str::isUuid($uuid)) {
            return null;
        }

        $pages = (int) ($event['fax-document-transferred-pages'] ?? 0);
        $success = ($event['fax-success'] ?? '0') === '1';
        $number = FaxNumber::find((int) ($event['variable_fax2email_number_id'] ?? 0))
            ?? ($file ? $this->numberFromFile($file) : null);

        $fax = Fax::firstOrNew(['call_uuid' => $uuid]);
        if ($fax->exists) {
            return $fax;
        }

        $fax->fill([
            'account_id' => $number?->account_id,
            'fax_number_id' => $number?->id,
            'status' => $success || $pages > 0 ? 'received' : 'failed',
            'called_number' => $number?->number ?? FaxNumber::normalize((string) ($event['Caller-Destination-Number'] ?? '')),
            'caller_number' => mb_substr((string) ($event['Caller-Caller-ID-Number'] ?? ''), 0, 64) ?: null,
            'caller_name' => mb_substr((string) ($event['Caller-Caller-ID-Name'] ?? ''), 0, 255) ?: null,
            'remote_station_id' => mb_substr(trim((string) ($event['fax-remote-station-id'] ?? '')), 0, 255) ?: null,
            'pages' => $pages,
            'result_text' => mb_substr((string) ($event['fax-result-text'] ?? ''), 0, 255) ?: null,
            'tiff_path' => $file && is_file($file) ? $file : null,
        ])->save();

        if ($fax->status === 'received') {
            ProcessReceivedFax::dispatch($fax->id);
        } elseif ($fax->tiff_path) {
            // Nothing usable arrived; don't keep an empty file around.
            @unlink($fax->tiff_path);
            $fax->update(['tiff_path' => null]);
        }

        return $fax;
    }

    /**
     * Queue any received file that has no record yet and has not changed for a few minutes.
     *
     * @return int Number of faxes recovered.
     */
    public function sweep(int $minAgeSeconds = 300): int
    {
        $recovered = 0;
        foreach (glob(config('fax2email.freeswitch.fax_dir').'/*.tif') ?: [] as $file) {
            if (filemtime($file) > time() - $minAgeSeconds) {
                continue;
            }
            $uuid = $this->uuidFromFile($file);
            if ($uuid === null || Fax::where('call_uuid', $uuid)->exists()) {
                continue;
            }

            $number = $this->numberFromFile($file);
            $fax = Fax::create([
                'call_uuid' => $uuid,
                'account_id' => $number?->account_id,
                'fax_number_id' => $number?->id,
                'status' => 'received',
                'called_number' => $number?->number,
                'result_text' => 'Recovered from a fax file (call details were not recorded)',
                'tiff_path' => $file,
            ]);
            ProcessReceivedFax::dispatch($fax->id);
            $recovered++;
        }

        return $recovered;
    }

    /**
     * Files are named <date>-<fax number id>-<call uuid>.tif by the dialplan.
     */
    private function numberFromFile(string $file): ?FaxNumber
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}-(\d+)-/', basename($file), $m) ? FaxNumber::find((int) $m[1]) : null;
    }

    private function uuidFromFile(string $file): ?string
    {
        return preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.tif$/i', basename($file), $m) ? strtolower($m[1]) : null;
    }
}
