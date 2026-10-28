<?php

namespace App\Support\Fax;

use App\Jobs\SendFax;
use App\Mail\FaxSendResult;
use App\Models\Fax;
use App\Models\FaxNumber;
use App\Models\User;
use App\Support\Freeswitch\EventSocket;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Sending faxes: queue them, place the call through the number's trunk,
 * record the result from FreeSWITCH, retry busy/no-answer calls, and tell
 * the sender how it went.
 */
class FaxOutbox
{
    /**
     * Hangup causes worth trying again later. Anything else (e.g. a number
     * that does not exist) fails straight away.
     */
    private const RETRY_CAUSES = [
        'USER_BUSY', 'NO_ANSWER', 'NO_USER_RESPONSE', 'NORMAL_TEMPORARY_FAILURE', 'RECOVERY_ON_TIMER_EXPIRE',
        'SWITCH_CONGESTION', 'NORMAL_CIRCUIT_CONGESTION', 'DESTINATION_OUT_OF_ORDER', 'NETWORK_OUT_OF_ORDER',
        'ORIGINATOR_CANCEL', 'NORMAL_CLEARING', 'NO_RESULT',
    ];

    private const CAUSE_TEXT = [
        'USER_BUSY' => 'The line was busy.',
        'NO_ANSWER' => 'Nobody answered.',
        'NO_USER_RESPONSE' => 'Nobody answered.',
        'UNALLOCATED_NUMBER' => 'The number is not in service.',
        'INVALID_NUMBER_FORMAT' => 'The number is not valid.',
        'NO_ROUTE_DESTINATION' => 'The provider could not route the call.',
        'CALL_REJECTED' => 'The call was rejected.',
        'INCOMPATIBLE_DESTINATION' => 'The number does not accept faxes.',
        'NORMAL_TEMPORARY_FAILURE' => 'The provider had a temporary problem.',
        'RECOVERY_ON_TIMER_EXPIRE' => 'The provider did not respond.',
        'GATEWAY_DOWN' => 'The SIP trunk is down.',
        'NO_RESULT' => 'The call ended without a result.',
    ];

    /**
     * @param  (\Closure(): EventSocket)|null  $connect  Opens the connection used to dial; tests pass a fake.
     */
    public function __construct(private ?\Closure $connect = null) {}

    /**
     * Queue a fax. $pdfPaths are files on the local disk, sent in this order.
     *
     * @param  list<string>  $pdfPaths
     */
    public function queue(FaxNumber $from, string $destination, ?string $destinationName, array $pdfPaths, ?User $user, string $senderEmail, string $source): Fax
    {
        $fax = Fax::create([
            'account_id' => $from->account_id,
            'fax_number_id' => $from->id,
            'direction' => 'outbound',
            'source' => $source,
            'user_id' => $user?->id,
            'sender_email' => $senderEmail,
            'status' => 'queued',
            'caller_number' => $from->number,
            'destination_number' => $destination,
            'destination_name' => $destinationName ? mb_substr($destinationName, 0, 255) : null,
        ]);

        // Keep the uploads under the fax's own folder until they are converted.
        $disk = Storage::disk('local');
        foreach (array_values($pdfPaths) as $i => $path) {
            $disk->move($path, "faxes/uploads/{$fax->id}/".sprintf('%02d', $i).'.pdf');
        }

        SendFax::dispatch($fax->id);

        return $fax;
    }

    /**
     * Convert (first time only) and dial. Called by the SendFax job.
     */
    public function attempt(Fax $fax): void
    {
        if ($fax->status !== 'queued') {
            return;
        }
        $disk = Storage::disk('local');

        if (! $fax->tiff_path) {
            $folder = sprintf('faxes/%d/%s', $fax->account_id, $fax->created_at->format('Y/m'));
            $disk->makeDirectory($folder);
            $uploads = collect($disk->files("faxes/uploads/{$fax->id}"))->sort()->values();

            try {
                app(PdfToFax::class)->convert($uploads->map(fn ($f) => $disk->path($f))->all(), $disk->path("{$folder}/{$fax->id}.tif"));
                // A PDF of exactly what is faxed, for the portal and the confirmation email.
                $pages = app(TiffToPdf::class)->convert($disk->path("{$folder}/{$fax->id}.tif"), $disk->path("{$folder}/{$fax->id}.pdf"));
            } catch (Throwable $e) {
                $this->finish($fax, 'failed', $e->getMessage());

                return;
            }

            $disk->deleteDirectory("faxes/uploads/{$fax->id}");
            $fax->update(['tiff_path' => "{$folder}/{$fax->id}.tif", 'pdf_path' => "{$folder}/{$fax->id}.pdf", 'pages' => $pages]);
        }

        $from = $fax->faxNumber;
        $trunk = $from?->sendingTrunk();
        if (! $from || ! $trunk) {
            $this->finish($fax, 'failed', 'No SIP trunk is available for sending faxes.');

            return;
        }

        $vars = [
            'fax2email_fax_id' => (string) $fax->id,
            'verbose_events' => 'true',
            'ignore_early_media' => 'true',
            'originate_timeout' => '60',
            'absolute_codec_string' => 'PCMU\\,PCMA',
            'origination_caller_id_number' => $trunk->dialString($from->number),
            'origination_caller_id_name' => $this->clean($from->account->name, 30),
            'fax_ident' => mb_substr('+'.$from->number, 0, 20),
            'fax_header' => $this->clean($from->account->name, 40),
            'fax_timezone' => self::headerTimezone($from->account->timezone),
            'fax_enable_t38' => 'true',
            'fax_enable_t38_request' => 'true',
            'fax_use_ecm' => 'true',
            'fax_verbose' => 'false',
        ];
        $command = sprintf(
            'originate {%s}sofia/gateway/%s/%s &txfax(%s)',
            // Values with spaces must be quoted, or FreeSWITCH splits the command there.
            implode(',', array_map(fn ($k, $v) => str_contains($v, ' ') ? "{$k}='{$v}'" : "{$k}={$v}", array_keys($vars), $vars)),
            $trunk->gatewayName(),
            $trunk->dialString($fax->destination_number),
            $disk->path($fax->tiff_path),
        );

        $fax->forceFill(['status' => 'sending', 'attempts' => $fax->attempts + 1, 'next_attempt_at' => null])->save();

        // Waits until the far end answers (up to the 60-second originate timeout).
        $esl = ($this->connect ?? function () {
            $config = config('fax2email.freeswitch.event_socket');

            return new EventSocket($config['host'], $config['port'], $config['password'], timeout: 90);
        })();
        try {
            $reply = trim($esl->api($command));
        } catch (RuntimeException $e) {
            // FreeSWITCH is unreachable: put the fax back without using up an attempt.
            $fax->forceFill(['status' => 'queued', 'attempts' => $fax->attempts - 1, 'next_attempt_at' => now()->addMinute()])->save();
            SendFax::dispatch($fax->id)->delay(now()->addMinute());

            return;
        } finally {
            $esl->close();
        }

        if (preg_match('/^\+OK\s+([0-9a-f-]{36})/i', $reply, $m)) {
            // Answered: the result arrives with the hangup event (see recordHangup).
            $fax->update(['call_uuid' => $m[1]]);

            return;
        }

        $cause = preg_match('/^-ERR\s+([A-Z_]+)/', $reply, $m) ? $m[1] : 'NORMAL_TEMPORARY_FAILURE';
        $this->failedAttempt($fax, $cause, null);
    }

    /**
     * Handle CHANNEL_HANGUP_COMPLETE for an outgoing fax call.
     *
     * @param  array<string, string>  $event
     */
    public function recordHangup(array $event): ?Fax
    {
        $fax = Fax::find((int) ($event['variable_fax2email_fax_id'] ?? 0));
        if (! $fax || $fax->status !== 'sending') {
            return $fax;
        }

        $pages = (int) ($event['variable_fax_document_transferred_pages'] ?? 0);
        $resultText = mb_substr((string) ($event['variable_fax_result_text'] ?? ''), 0, 255) ?: null;
        $fax->update([
            'remote_station_id' => mb_substr(trim((string) ($event['variable_fax_remote_station_id'] ?? '')), 0, 255) ?: $fax->remote_station_id,
            'hangup_cause' => $event['Hangup-Cause'] ?? null,
        ]);

        if (($event['variable_fax_success'] ?? '0') === '1') {
            $fax->update(['pages' => max($pages, $fax->pages)]);
            $this->finish($fax, 'sent', $resultText);

            return $fax;
        }

        $this->failedAttempt($fax, $event['Hangup-Cause'] ?? 'NO_RESULT', $resultText);

        return $fax;
    }

    /**
     * Recover faxes stuck in "sending" (no hangup event arrived) or "queued"
     * past their retry time (e.g. the queue was cleared). Run by the scheduler.
     */
    public function sweep(): void
    {
        Fax::where('direction', 'outbound')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(30))
            ->each(fn (Fax $fax) => $this->failedAttempt($fax, 'NO_RESULT', null));

        Fax::where('direction', 'outbound')->where('status', 'queued')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->where('updated_at', '<', now()->subMinutes(10))->orWhere('next_attempt_at', '<', now()->subMinutes(10)))
            ->each(fn (Fax $fax) => SendFax::dispatch($fax->id));
    }

    public static function describe(?string $cause, ?string $resultText): string
    {
        return self::CAUSE_TEXT[$cause] ?? ($resultText ?: ($cause ? ucfirst(strtolower(str_replace('_', ' ', $cause))).'.' : 'Unknown error.'));
    }

    private function failedAttempt(Fax $fax, string $cause, ?string $resultText): void
    {
        $fax->update(['hangup_cause' => $cause, 'result_text' => $resultText ?? self::describe($cause, null)]);

        if (in_array($cause, self::RETRY_CAUSES, true) && $fax->attempts < Fax::MAX_ATTEMPTS) {
            $retryAt = now()->addMinutes(Fax::RETRY_AFTER_MINUTES);
            $fax->forceFill(['status' => 'queued', 'next_attempt_at' => $retryAt])->save();
            SendFax::dispatch($fax->id)->delay($retryAt);

            return;
        }

        $this->finish($fax, 'failed', self::describe($cause, $resultText));
    }

    private function finish(Fax $fax, string $status, ?string $resultText): void
    {
        $fax->forceFill(['status' => $status, 'result_text' => $resultText, 'completed_at' => now(), 'next_attempt_at' => null])->save();
        Storage::disk('local')->deleteDirectory("faxes/uploads/{$fax->id}");

        // The TIFF was only needed for the call; the PDF stays for the portal.
        if ($fax->tiff_path) {
            Storage::disk('local')->delete($fax->tiff_path);
            $fax->update(['tiff_path' => null]);
        }

        if ($fax->sender_email) {
            try {
                Mail::to($fax->sender_email)->send(new FaxSendResult($fax));
                $fax->forceFill(['emailed_at' => now(), 'email_error' => null])->save();
            } catch (Throwable $e) {
                $fax->update(['email_error' => $e->getMessage()]);
            }
        }
    }

    /**
     * The zone for the time printed in the fax header, as a POSIX TZ string
     * fixed at the zone's current offset (e.g. "EDT4" for Toronto in summer).
     * spandsp only understands POSIX strings, and a call is over long before
     * the offset could change; each retry works it out again.
     */
    public static function headerTimezone(?string $zone): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($zone ?: 'UTC'));
        // POSIX offsets count hours west of UTC, so the sign is flipped.
        $west = -$now->getOffset();
        $offset = ($west < 0 ? '-' : '').intdiv(abs($west), 3600).(abs($west) % 3600 ? sprintf(':%02d', intdiv(abs($west) % 3600, 60)) : '');
        // spandsp needs a name of three or more letters; zones like "+0530" don't have one.
        $name = preg_match('/^[A-Za-z]{3,}$/', $now->format('T')) ? $now->format('T') : 'LOC';

        return $name.$offset;
    }

    /**
     * Names go into FreeSWITCH variables, so keep them to safe characters.
     */
    private function clean(string $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/[^A-Za-z0-9 .&-]/', '', $value)), 0, $max) ?: 'Fax';
    }
}
