<?php

namespace App\Console\Commands;

use App\Support\Https;
use Illuminate\Console\Command;

/**
 * Used by the root-owned fax2email-https-apply service, which runs this
 * command as www-data to read the admin's HTTPS settings and report results.
 */
class HttpsCommand extends Command
{
    protected $signature = 'fax2email:https
                            {action : export | status}
                            {--state= : For "status": off, active or error}
                            {--message= : For "status": what happened}
                            {--domain= : For "status": the domain the certificate is for}
                            {--issuer= : For "status": who issued the certificate}
                            {--expires= : For "status": when the certificate expires (ISO 8601)}';

    protected $description = 'Exchange HTTPS settings with the fax2email-https-apply service';

    public function handle(): int
    {
        if ($this->argument('action') === 'export') {
            $this->output->write(Https::export());

            return self::SUCCESS;
        }

        if ($this->argument('action') === 'status') {
            Https::recordStatus([
                'state' => in_array($this->option('state'), ['off', 'active', 'error'], true) ? $this->option('state') : 'error',
                'message' => $this->option('message') ? mb_substr($this->option('message'), 0, 1000) : null,
                'domain' => $this->option('domain') ?: null,
                'issuer' => $this->option('issuer') ?: null,
                'expires_at' => $this->option('expires') ?: null,
            ]);

            return self::SUCCESS;
        }

        $this->components->error('Unknown action. Use export or status.');

        return self::INVALID;
    }
}
