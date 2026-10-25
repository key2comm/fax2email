<?php

namespace App\Console\Commands;

use App\Support\Fax\FaxInbox;
use App\Support\Fax\FaxOutbox;
use App\Support\Freeswitch\EventSocket;
use Illuminate\Console\Command;
use Throwable;

/**
 * Long-running listener for FreeSWITCH's "fax received" events. Runs as the
 * fax2email-fax-events systemd service and reconnects if FreeSWITCH restarts.
 */
class FaxEventsCommand extends Command
{
    protected $signature = 'fax2email:fax-events';

    protected $description = 'Record faxes as FreeSWITCH finishes receiving or sending them';

    public function handle(FaxInbox $inbox, FaxOutbox $outbox): int
    {
        while (true) {
            try {
                $this->components->info('Listening for fax events.');
                EventSocket::fromConfig()->listen('CHANNEL_HANGUP_COMPLETE CUSTOM spandsp::rxfaxresult', function (array $event) use ($inbox, $outbox) {
                    // Hangups matter only for faxes we are sending (marked with fax2email_fax_id).
                    if (($event['Event-Name'] ?? '') === 'CHANNEL_HANGUP_COMPLETE') {
                        if (isset($event['variable_fax2email_fax_id']) && ($fax = $outbox->recordHangup($event))) {
                            $this->line("Sent fax {$fax->id}: {$fax->status} ({$event['Hangup-Cause']})");
                        }

                        return;
                    }

                    $fax = $inbox->recordEvent($event);
                    $this->line($fax ? "Fax {$fax->id}: {$fax->status}, {$fax->pages} page(s) from ".($fax->caller_number ?: 'unknown') : 'Ignored an event without a call id');
                });
            } catch (Throwable $e) {
                $this->components->warn($e->getMessage());
            }

            // Connection lost or FreeSWITCH not running yet; try again shortly.
            sleep(5);
        }
    }
}
