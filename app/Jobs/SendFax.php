<?php

namespace App\Jobs;

use App\Models\Fax;
use App\Support\Fax\FaxOutbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One attempt at sending a fax. Retries are new SendFax jobs scheduled by
 * FaxOutbox, so this job itself is not retried.
 */
class SendFax implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Dialling waits up to a minute for an answer.
     */
    public int $timeout = 300;

    public function __construct(public int $faxId) {}

    public function handle(FaxOutbox $outbox): void
    {
        $fax = Fax::with('faxNumber.account')->find($this->faxId);
        if ($fax) {
            $outbox->attempt($fax);
        }
    }
}
