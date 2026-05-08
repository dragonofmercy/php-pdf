<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

/**
 * Encodes a UTF-8 string into a sequence of 2-byte big-endian glyph indices,
 * suitable for emission as a hex string in a PDF content stream when the
 * active font is a CIDFont/Type0 with Identity-H encoding.
 *
 * Codepoints absent from the cmap are replaced silently by GID 0 (.notdef),
 * matching the robustness convention used by WinAnsiEncoder ('?').
 *
 * @internal
 */
final class Utf8ToCidEncoder
{
    public static function encode(string $utf8, ParsedTtf $font): string
    {
        $output = '';
        $i = 0;
        $len = strlen($utf8);
        while ($i < $len) {
            $b0 = ord($utf8[$i]);
            if ($b0 < 0x80) {
                $cp = $b0;
                $i++;
            } elseif (($b0 & 0xE0) === 0xC0 && $i + 1 < $len) {
                $cp = (($b0 & 0x1F) << 6) | (ord($utf8[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($b0 & 0xF0) === 0xE0 && $i + 2 < $len) {
                $cp = (($b0 & 0x0F) << 12)
                    | ((ord($utf8[$i + 1]) & 0x3F) << 6)
                    | (ord($utf8[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (($b0 & 0xF8) === 0xF0 && $i + 3 < $len) {
                $cp = (($b0 & 0x07) << 18)
                    | ((ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((ord($utf8[$i + 2]) & 0x3F) << 6)
                    | (ord($utf8[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $cp = -1;
                $i++;
            }
            $gid = $cp >= 0 ? ($font->cmap[$cp] ?? 0) : 0;
            $output .= chr(($gid >> 8) & 0xFF) . chr($gid & 0xFF);
        }
        return $output;
    }
}
