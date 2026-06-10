<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;

/**
 * FlateDecode (PDF 1.7 7.4.4): zlib/deflate decompression plus the optional
 * predictor post-step driven by /DecodeParms.
 *
 * @internal
 */
final readonly class FlateDecoder
{
    public static function decode(
        string $data,
        int $predictor = 1,
        int $colors = 1,
        int $bitsPerComponent = 8,
        int $columns = 1,
    ): string {
        $out = @gzuncompress($data);
        if ($out === false) {
            // tolerate streams written as raw deflate (no zlib wrapper) or
            // with a corrupt adler checksum
            $out = @gzinflate($data);
        }
        if ($out === false) {
            $out = @gzinflate(substr($data, 2));
        }
        if ($out === false) {
            throw new PdfParseException('FlateDecode failed: data is not valid zlib or raw deflate');
        }
        if ($predictor > 1) {
            return PredictorDecoder::apply($out, $predictor, $colors, $bitsPerComponent, $columns);
        }
        return $out;
    }
}
