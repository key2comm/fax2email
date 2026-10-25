<?php

namespace App\Console\Commands;

use App\Models\SipTrunk;
use App\Support\Freeswitch\EventSocket;
use Illuminate\Console\Command;
use RuntimeException;
use SimpleXMLElement;

/**
 * Reads each trunk's registration (or OPTIONS ping) state from FreeSWITCH
 * and stores it for the SIP trunks page. Scheduled every minute.
 */
class TrunkStatusCommand extends Command
{
    protected $signature = 'fax2email:trunk-status';

    protected $description = 'Refresh SIP trunk registration status from FreeSWITCH';

    public function handle(): int
    {
        $trunks = SipTrunk::all();
        if ($trunks->isEmpty()) {
            return self::SUCCESS;
        }

        $esl = EventSocket::fromConfig();
        try {
            $xml = $esl->api('sofia xmlstatus gateway');
        } catch (RuntimeException $e) {
            SipTrunk::query()->update(['status' => 'unknown', 'status_detail' => $e->getMessage(), 'status_checked_at' => now()]);
            $this->components->warn($e->getMessage());

            return self::SUCCESS;
        } finally {
            $esl->close();
        }

        $gateways = [];
        $parsed = @simplexml_load_string($xml);
        foreach ($parsed instanceof SimpleXMLElement ? $parsed->gateway : [] as $gateway) {
            $gateways[(string) $gateway->name] = $gateway;
        }

        foreach ($trunks as $trunk) {
            $gateway = $gateways[$trunk->gatewayName()] ?? null;
            [$status, $detail] = match (true) {
                ! $trunk->enabled => ['disabled', null],
                $gateway === null => ['unknown', 'Not loaded in FreeSWITCH yet'],
                $trunk->registers() => match ((string) $gateway->state) {
                    'REGED' => ['registered', null],
                    'TRYING', 'REGISTER', 'UNREGED' => ['trying', 'Registering…'],
                    'FAIL_WAIT', 'FAILED', 'NOREG' => ['failed', 'The provider did not accept the registration. Check the username, password, host, port and protocol.'],
                    default => ['trying', (string) $gateway->state],
                },
                default => ((string) $gateway->status) === 'UP' ? ['reachable', null] : ['unreachable', 'No reply to OPTIONS pings'],
            };

            $trunk->forceFill(['status' => $status, 'status_detail' => $detail ?: null, 'status_checked_at' => now()])->saveQuietly();
        }

        return self::SUCCESS;
    }
}
