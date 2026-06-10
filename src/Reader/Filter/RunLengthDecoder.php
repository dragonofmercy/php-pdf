<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;

/**
 * RunLengthDecode (PDF 1.7 7.4.5).
 *
 * @internal
 */
final readonly class RunLengthDecoder
{
    public static function decode(string $data): string
    {
        $out = '';
        $pos = 0;
        $length = strlen($data);
        while ($pos < $length) {
            $control = ord($data[$pos]);
            $pos++;
            if ($control === 128) {
                return $out;
            }
            if ($control < 128) {
                $count = $control + 1;
                if ($pos + $count > $length) {
                    throw new PdfParseException("RunLengthDecode literal run truncated at offset {$pos}");
                }
                $out .= substr($data, $pos, $count);
                $pos += $count;
                continue;
            }
            if ($pos >= $length) {
                throw new PdfParseException("RunLengthDecode repeat run truncated at offset {$pos}");
            }
            $out .= str_repeat($data[$pos], 257 - $control);
            $pos++;
        }
        return $out; // missing EOD tolerated
    }
}
