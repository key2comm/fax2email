<?php

namespace App\Jobs;

use App\Mail\FaxReceived;
use App\Models\Fax;
use App\Support\Fax\TiffToPdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Files a received fax under its account, makes the PDF, and emails it to
 * the fax number's recipients.
 */
class ProcessReceivedFax implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Seconds to wait before retrying, e.g. while the mail server is down.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public int $faxId) {}

    public function handle(TiffToPdf $converter): void
    {
        $fax = Fax::with('faxNumber.account')->find($this->faxId);
        if (! $fax || $fax->status === 'delivered') {
            return;
        }

        $disk = Storage::disk('local');

        // Move the TIFF out of FreeSWITCH's drop folder into the account's folder.
        if ($fax->tiff_path && str_starts_with($fax->tiff_path, '/')) {
            if (! is_file($fax->tiff_path)) {
                $fax->update(['status' => 'failed', 'result_text' => 'The fax file is missing.']);

                return;
            }
            $relative = sprintf('faxes/%s/%s/%d.tif', $fax->account_id ?? 'unassigned', $fax->created_at->format('Y/m'), $fax->id);
            $disk->makeDirectory(dirname($relative));
            rename($fax->tiff_path, $disk->path($relative));
            $fax->update(['tiff_path' => $relative]);
        }

        if (! $fax->pdf_path) {
            $pdf = preg_replace('/\.tif$/', '.pdf', $fax->tiff_path);
            try {
                $pages = $converter->convert($disk->path($fax->tiff_path), $disk->path($pdf));
            } catch (Throwable $e) {
                $fax->update(['status' => 'failed', 'result_text' => 'Could not convert the fax to PDF: '.$e->getMessage()]);

                return;
            }
            $fax->update(['pdf_path' => $pdf, 'pages' => max($fax->pages, $pages)]);
        }

        $recipients = $fax->faxNumber?->deliveryAddresses() ?? [];
        if ($recipients === []) {
            $fax->update(['status' => 'delivered', 'email_error' => 'No one to email: the number has no recipients and its account has no owners.']);

            return;
        }

        try {
            Mail::to($recipients)->send(new FaxReceived($fax));
        } catch (Throwable $e) {
            $fax->update(['email_error' => $e->getMessage()]);

            throw $e; // Retried later; see $backoff.
        }

        $fax->forceFill(['status' => 'delivered', 'emailed_at' => now(), 'email_error' => null])->save();
    }
}
