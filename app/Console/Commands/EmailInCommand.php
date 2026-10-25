<?php

namespace App\Console\Commands;

use App\Support\Fax\EmailToFax;
use Illuminate\Console\Command;
use Throwable;

/**
 * Processes emails Postfix has delivered to the email-to-fax spool (a
 * Maildir). Started by the fax2email-email-in systemd path unit as soon as
 * a message arrives; each message is removed once handled.
 */
class EmailInCommand extends Command
{
    protected $signature = 'fax2email:email-in';

    protected $description = 'Turn emails to <number>@<fax domain> into faxes';

    public function handle(EmailToFax $emailToFax): int
    {
        $new = rtrim(config('fax2email.mail.spool'), '/').'/new';

        foreach (glob("{$new}/*") ?: [] as $file) {
            try {
                $log = $emailToFax->handle((string) file_get_contents($file));
                $this->line("{$log->status}: {$log->from_address} -> {$log->recipient}".($log->reason ? " ({$log->reason})" : ''));
            } catch (Throwable $e) {
                $this->components->error(basename($file).': '.$e->getMessage());
                report($e);
            }
            // Handled or unreadable, never retried in a loop.
            @unlink($file);
        }

        return self::SUCCESS;
    }
}
