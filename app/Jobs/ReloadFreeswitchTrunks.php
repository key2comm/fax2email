<?php

namespace App\Jobs;

use App\Support\Freeswitch\EventSocket;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells FreeSWITCH to fetch the trunk configuration again after a change.
 * Runs on the queue (as www-data under systemd), not in the web request.
 */
class ReloadFreeswitchTrunks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const PROFILES = ['fax2email', 'fax2email-tls'];

    public int $tries = 5;

    public int $backoff = 10;

    /**
     * @param  list<array{profile: string, gateway: string}>  $removeGateways  Gateways to stop first (deleted or changed trunks).
     */
    public function __construct(public array $removeGateways = []) {}

    public function uniqueId(): string
    {
        return md5(serialize($this->removeGateways));
    }

    public function handle(): void
    {
        $esl = EventSocket::fromConfig();

        try {
            foreach ($this->removeGateways as $gateway) {
                $esl->api("sofia profile {$gateway['profile']} killgw {$gateway['gateway']}");
            }

            $esl->api('reloadacl');

            foreach (self::PROFILES as $profile) {
                $status = $esl->api("sofia status profile {$profile}");
                // A profile that failed to start (e.g. no TLS certificate yet) is started instead of rescanned.
                $esl->api(str_contains($status, 'Invalid Profile') ? "sofia profile {$profile} start" : "sofia profile {$profile} rescan");
            }
        } finally {
            $esl->close();
        }
    }
}
