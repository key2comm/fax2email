<?php

namespace App\Support\Fax;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Converts fax TIFFs (as written by FreeSWITCH/spandsp) to PDF without any
 * external tools. Fax pages are stored CCITT G3/G4-compressed, and PDF can
 * hold that data unchanged (CCITTFaxDecode), so pages are copied byte for
 * byte: no decoding, no quality loss. Uncompressed bilevel pages also work.
 * Ghostscript then re-encodes the images as Flate (see reencodeImages()).
 *
 * Page size follows the fax resolution, so 204x98 "normal" and 204x196
 * "fine" faxes both come out at their real size (about US Letter / A4).
 */
class TiffToPdf
{
    private const TAG_WIDTH = 256;

    private const TAG_LENGTH = 257;

    private const TAG_BITS = 258;

    private const TAG_COMPRESSION = 259;

    private const TAG_PHOTOMETRIC = 262;

    private const TAG_FILL_ORDER = 266;

    private const TAG_STRIP_OFFSETS = 273;

    private const TAG_ROWS_PER_STRIP = 278;

    private const TAG_STRIP_BYTES = 279;

    private const TAG_X_RES = 282;

    private const TAG_Y_RES = 283;

    private const TAG_T4_OPTIONS = 292;

    private const TAG_RES_UNIT = 296;

    private string $data;

    private bool $littleEndian;

    /**
     * @return int Number of pages written.
     */
    public function convert(string $tiffPath, string $pdfPath): int
    {
        $data = @file_get_contents($tiffPath);
        if ($data === false || strlen($data) < 8) {
            throw new RuntimeException("Cannot read {$tiffPath}.");
        }

        $pages = $this->readPages($data);
        if ($pages === []) {
            throw new RuntimeException('The TIFF contains no pages.');
        }

        if (file_put_contents($pdfPath, $this->buildPdf($pages)) === false) {
            throw new RuntimeException("Cannot write {$pdfPath}.");
        }

        $this->reencodeImages($pdfPath);

        return count($pages);
    }

    /**
     * Some PDF viewers (Edge's built-in one among them) show CCITT fax images
     * as blank pages. Ghostscript decodes them and stores plain Flate bitmaps
     * instead, at full resolution, which every viewer can show. If that fails
     * the CCITT PDF is kept: it is valid, and most viewers show it fine.
     */
    private function reencodeImages(string $pdfPath): void
    {
        $flatePath = "{$pdfPath}.flate";
        $result = Process::timeout(120)->run([
            'gs', '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE',
            '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4',
            '-dAutoFilterMonoImages=false', '-dEncodeMonoImages=true', '-dMonoImageFilter=/FlateEncode', '-dDownsampleMonoImages=false',
            '-sOutputFile='.$flatePath,
            $pdfPath,
        ]);

        if ($result->successful() && is_file($flatePath) && filesize($flatePath) > 0) {
            rename($flatePath, $pdfPath);
        } else {
            @unlink($flatePath);
        }
    }

    /**
     * @return list<array{width: int, height: int, xres: float, yres: float, strips: list<array{rows: int, data: string}>, params: string}>
     */
    private function readPages(string $data): array
    {
        $this->data = $data;
        $this->littleEndian = match (substr($data, 0, 4)) {
            "II*\0" => true,
            "MM\0*" => false,
            default => throw new RuntimeException('Not a TIFF file.'),
        };

        $pages = [];
        $offset = $this->u32(4);
        $seen = [];
        while ($offset !== 0 && ! isset($seen[$offset]) && count($pages) < 500) {
            $seen[$offset] = true;
            [$tags, $offset] = $this->readIfd($offset);
            $pages[] = $this->page($tags);
        }

        return $pages;
    }

    /**
     * @return array{0: array<int, list<int|float>>, 1: int}
     */
    private function readIfd(int $offset): array
    {
        $count = $this->u16($offset);
        $tags = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = $offset + 2 + $i * 12;
            $tag = $this->u16($entry);
            $type = $this->u16($entry + 2);
            $n = $this->u32($entry + 4);
            $size = match ($type) {
                3 => 2, 4 => 4, 5 => 8, default => 1,
            };
            $valueOffset = $n * $size > 4 ? $this->u32($entry + 8) : $entry + 8;

            $values = [];
            for ($j = 0; $j < min($n, 100000); $j++) {
                $at = $valueOffset + $j * $size;
                $values[] = match ($type) {
                    3 => $this->u16($at),
                    4 => $this->u32($at),
                    5 => $this->u32($at) / max(1, $this->u32($at + 4)),
                    default => ord($this->data[$at] ?? "\0"),
                };
            }
            $tags[$tag] = $values;
        }

        return [$tags, $this->u32($offset + 2 + $count * 12)];
    }

    /**
     * @param  array<int, list<int|float>>  $tags
     * @return array{width: int, height: int, xres: float, yres: float, strips: list<array{rows: int, data: string}>, params: string}
     */
    private function page(array $tags): array
    {
        $get = fn (int $tag, $default = null) => $tags[$tag][0] ?? $default;

        $width = (int) $get(self::TAG_WIDTH, 0);
        $height = (int) $get(self::TAG_LENGTH, 0);
        if ($width < 1 || $height < 1 || (int) $get(self::TAG_BITS, 1) !== 1) {
            throw new RuntimeException('Only black-and-white fax pages are supported.');
        }

        $compression = (int) $get(self::TAG_COMPRESSION, 1);
        $t4Options = (int) $get(self::TAG_T4_OPTIONS, 0);
        // min-is-white (0) is standard for fax; min-is-black flips the colours.
        $minIsBlack = (int) $get(self::TAG_PHOTOMETRIC, 0) === 1;
        $params = match ($compression) {
            // T.4 (Group 3): 1-D, or 2-D when bit 0 of T4Options is set. TIFF's fill bits (bit 2) pad
            // before each EOL, which decoders skip anyway. They are not PDF's EncodedByteAlign: in 2-D
            // a line starts one tag bit after the EOL, so strict viewers (pdf.js) render it blank.
            3 => sprintf('/K %d /EndOfLine true', $t4Options & 1 ? 1 : 0),
            // T.6 (Group 4).
            4 => '/K -1',
            1 => '',
            default => throw new RuntimeException("Unsupported TIFF compression ({$compression})."),
        };
        if ($params !== '') {
            $params = "/Filter /CCITTFaxDecode /DecodeParms << {$params} /Columns {$width} /BlackIs1 ".($minIsBlack ? 'true' : 'false').' /Rows %ROWS% >>';
        } elseif (! $minIsBlack) {
            // Uncompressed min-is-white: 0 bits are white, the opposite of DeviceGray.
            $params = '/Decode [1 0]';
        }

        // Resolution in dots per inch (unit 3 = centimetres). Faxes default to 204x196.
        $perCm = (int) $get(self::TAG_RES_UNIT, 2) === 3;
        $xres = (float) $get(self::TAG_X_RES, 204) * ($perCm ? 2.54 : 1);
        $yres = (float) $get(self::TAG_Y_RES, 196) * ($perCm ? 2.54 : 1);

        $offsets = $tags[self::TAG_STRIP_OFFSETS] ?? [];
        $counts = $tags[self::TAG_STRIP_BYTES] ?? [];
        $rowsPerStrip = (int) $get(self::TAG_ROWS_PER_STRIP, $height);
        $reverseBits = (int) $get(self::TAG_FILL_ORDER, 1) === 2;

        $strips = [];
        $remaining = $height;
        foreach ($offsets as $i => $stripOffset) {
            $bytes = substr($this->data, (int) $stripOffset, (int) ($counts[$i] ?? 0));
            $rows = min($rowsPerStrip, $remaining);
            $remaining -= $rows;
            // PDF expects the most significant bit first; spandsp often writes least significant first.
            $strips[] = ['rows' => $rows, 'data' => $reverseBits ? strtr($bytes, self::bitReverseTable()) : $bytes];
        }

        return [
            'width' => $width,
            'height' => $height,
            'xres' => $xres > 0 ? $xres : 204.0,
            'yres' => $yres > 0 ? $yres : 196.0,
            'strips' => $strips,
            'params' => $params,
        ];
    }

    /**
     * @param  list<array{width: int, height: int, xres: float, yres: float, strips: list<array{rows: int, data: string}>, params: string}>  $pages
     */
    private function buildPdf(array $pages): string
    {
        $objects = [];
        $pageIds = [];
        $next = 3; // 1 = catalog, 2 = page tree

        foreach ($pages as $page) {
            $pageWidth = $page['width'] / $page['xres'] * 72;
            $pageHeight = $page['height'] / $page['yres'] * 72;

            $pageId = $next++;
            $contentId = $next++;
            $content = '';
            $xobjects = '';
            $top = $pageHeight;

            // Each strip is its own image, stacked top to bottom (usually there is one strip per page).
            foreach ($page['strips'] as $i => $strip) {
                $imageId = $next++;
                $stripHeight = $strip['rows'] / $page['yres'] * 72;
                $top -= $stripHeight;
                $params = str_replace('%ROWS%', (string) $strip['rows'], $page['params']);
                $objects[$imageId] = "<< /Type /XObject /Subtype /Image /Width {$page['width']} /Height {$strip['rows']} "
                    ."/ColorSpace /DeviceGray /BitsPerComponent 1 {$params} /Length ".strlen($strip['data'])." >>\nstream\n{$strip['data']}\nendstream";
                $xobjects .= "/Im{$i} {$imageId} 0 R ";
                $content .= sprintf("q %.4F 0 0 %.4F 0 %.4F cm /Im%d Do Q\n", $pageWidth, $stripHeight, $top, $i);
            }

            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.4F %.4F] /Resources << /XObject << %s>> >> /Contents %d 0 R >>',
                $pageWidth, $pageHeight, $xobjects, $contentId,
            );
            $objects[$contentId] = '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream";
            $pageIds[] = "{$pageId} 0 R";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageIds).'] /Count '.count($pageIds).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer
<< /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    /**
     * @return array<string, string>
     */
    private static function bitReverseTable(): array
    {
        static $table = null;
        if ($table === null) {
            $table = [];
            for ($i = 0; $i < 256; $i++) {
                $table[chr($i)] = chr(bindec(strrev(str_pad(decbin($i), 8, '0', STR_PAD_LEFT))));
            }
        }

        return $table;
    }

    private function u16(int $offset): int
    {
        $bytes = substr($this->data, $offset, 2);
        if (strlen($bytes) < 2) {
            throw new RuntimeException('The TIFF file is truncated.');
        }

        return unpack($this->littleEndian ? 'v' : 'n', $bytes)[1];
    }

    private function u32(int $offset): int
    {
        $bytes = substr($this->data, $offset, 4);
        if (strlen($bytes) < 4) {
            throw new RuntimeException('The TIFF file is truncated.');
        }

        return unpack($this->littleEndian ? 'V' : 'N', $bytes)[1];
    }
}
