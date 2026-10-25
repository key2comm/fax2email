<?php

namespace App\Console\Commands;

use App\Support\Fax\FaxInbox;
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

    protected $description = 'Record faxes as FreeSWITCH finishes receiving them';

    public function handle(FaxInbox $inbox): int
    {
        while (true) {
            try {
                $this->components->info('Listening for fax events.');
                EventSocket::fromConfig()->listen('CUSTOM spandsp::rxfaxresult', function (array $event) use ($inbox) {
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
