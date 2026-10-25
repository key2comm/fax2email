<?php

namespace App\Console\Commands;

use App\Jobs\ReloadFreeswitchTrunks;
use App\Models\SipTrunk;
use App\Support\TrunkWhitelist;
use Illuminate\Console\Command;

/**
 * Looks up trunk hostnames again and updates the whitelist and FreeSWITCH's
 * trunk list if a provider's IPs changed. Scheduled every five minutes.
 */
class TrunkResolveCommand extends Command
{
    protected $signature = 'fax2email:trunk-resolve';

    protected $description = 'Re-resolve SIP trunk hostnames and update the whitelist';

    public function handle(TrunkWhitelist $whitelist): int
    {
        $changed = false;
        foreach (SipTrunk::all() as $trunk) {
            if ($whitelist->sync($trunk)) {
                $this->line("{$trunk->name}: addresses changed");
                $changed = true;
            }
        }

        if ($changed) {
            ReloadFreeswitchTrunks::dispatch();
        }

        return self::SUCCESS;
    }
}
