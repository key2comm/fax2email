<?php

namespace App\Support\Fax;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Renders PDFs into one fax-ready TIFF (G4, 204x196 dpi, 1728 dots wide on
 * US Letter) with Ghostscript. PDFs are joined in the order given. They come
 * from users and email, so Ghostscript runs in -dSAFER mode with a time limit.
 */
class PdfToFax
{
    /** Pixels for US Letter at fax "fine" resolution: 8.5in is trimmed to the 1728-dot fax width. */
    private const PAGE = '1728x2156';

    public const MAX_PAGES = 100;

    /**
     * @param  list<string>  $pdfPaths
     */
    public function convert(array $pdfPaths, string $tiffPath): void
    {
        if ($pdfPaths === []) {
            throw new RuntimeException('There is no PDF to send.');
        }
        foreach ($pdfPaths as $path) {
            $head = (string) @file_get_contents($path, false, null, 0, 1024);
            if (! str_contains($head, '%PDF-')) {
                throw new RuntimeException(basename($path).' is not a PDF file.');
            }
        }

        $result = Process::timeout(180)->run([
            'gs', '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNOINTERPOLATE',
            '-sDEVICE=tiffg4', '-r204x196', '-g'.self::PAGE, '-dFIXEDMEDIA', '-dPDFFitPage',
            '-dLastPage='.self::MAX_PAGES,
            '-sOutputFile='.$tiffPath,
            '-f', ...$pdfPaths,
        ]);

        if (! $result->successful() || ! is_file($tiffPath) || filesize($tiffPath) === 0) {
            @unlink($tiffPath);
            $error = trim($result->errorOutput() ?: $result->output());
            throw new RuntimeException('The PDF could not be converted to a fax'.($error !== '' ? ': '.mb_substr($error, 0, 300) : '.'));
        }
    }
}
