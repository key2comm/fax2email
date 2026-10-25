<?php

namespace App\Console\Commands;

use App\Support\Firewall;
use Illuminate\Console\Command;

/**
 * Used by the root-owned fax2email-firewall-apply service, which runs this
 * command as www-data. Not meant to be run by hand, although it is harmless.
 */
class FirewallCommand extends Command
{
    protected $signature = 'fax2email:firewall
                            {action : export | import-bans | status}
                            {--fail2ban= : For "status": 1 if fail2ban is running, 0 if not}
                            {--error= : For "status": the error that stopped the last apply, if any}';

    protected $description = 'Exchange firewall state with the fax2email-firewall-apply service';

    public function handle(): int
    {
        switch ($this->argument('action')) {
            case 'export':
                $this->output->write(Firewall::export());

                return self::SUCCESS;

            case 'import-bans':
                $lines = preg_split('/\R/', trim((string) stream_get_contents(STDIN)), flags: PREG_SPLIT_NO_EMPTY);
                $result = Firewall::importBans($lines);
                $this->line("banned {$result['banned']}, unbanned {$result['unbanned']}");

                return self::SUCCESS;

            case 'status':
                Firewall::recordStatus($this->option('error') ?: null, $this->option('fail2ban') === '1');

                return self::SUCCESS;

            default:
                $this->components->error('Unknown action. Use export, import-bans or status.');

                return self::INVALID;
        }
    }
}
