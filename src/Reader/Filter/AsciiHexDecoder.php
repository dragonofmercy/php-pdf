<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;

/**
 * ASCIIHexDecode (PDF 1.7 7.4.2).
 *
 * @internal
 */
final readonly class AsciiHexDecoder
{
    public static function decode(string $data): string
    {
        $end = strpos($data, '>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        $hex = preg_replace('/[\x00\t\n\x0C\r ]+/', '', $data);
        if ($hex === null) {
            throw new PdfParseException('ASCIIHexDecode failed to strip whitespace');
        }
        if ($hex !== '' && !ctype_xdigit($hex)) {
            throw new PdfParseException('ASCIIHexDecode input contains non-hex characters');
        }
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        $binary = hex2bin($hex);
        if ($binary === false) {
            throw new PdfParseException('ASCIIHexDecode failed');
        }
        return $binary;
    }
}
