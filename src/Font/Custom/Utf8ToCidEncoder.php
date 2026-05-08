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
        foreach (Utf8::codepoints($utf8) as [$cp, $_]) {
            $gid = $cp >= 0 ? ($font->cmap[$cp] ?? 0) : 0;
            $output .= chr(($gid >> 8) & 0xFF) . chr($gid & 0xFF);
        }
        return $output;
    }
}
