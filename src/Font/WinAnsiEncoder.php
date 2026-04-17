<?php

declare(strict_types=1);

namespace PhpPdf\Font;

/**
 * Maps UTF-8 strings to WinAnsiEncoding bytes (PDF 1.7 Annex D.2).
 * Unsupported codepoints are silently replaced by '?' (0x3F).
 *
 * @internal
 */
final class WinAnsiEncoder
{
    /**
     * Codepoints mapped to their WinAnsi byte for positions 0x80..0x9F.
     * ASCII (0x20-0x7E) and Latin-1 supplement (0xA0-0xFF) are handled
     * directly via ord(). Positions 0x7F, 0x81, 0x8D, 0x8F, 0x90, 0x9D
     * are undefined in WinAnsi and produce '?'.
     *
     * @var array<int, int>
     */
    private const MAP = [
        0x20AC => 0x80, // €
        0x201A => 0x82, // ‚
        0x0192 => 0x83, // ƒ
        0x201E => 0x84, // „
        0x2026 => 0x85, // …
        0x2020 => 0x86, // †
        0x2021 => 0x87, // ‡
        0x02C6 => 0x88, // ˆ
        0x2030 => 0x89, // ‰
        0x0160 => 0x8A, // Š
        0x2039 => 0x8B, // ‹
        0x0152 => 0x8C, // Œ
        0x017D => 0x8E, // Ž
        0x2018 => 0x91, // '
        0x2019 => 0x92, // '
        0x201C => 0x93, // "
        0x201D => 0x94, // "
        0x2022 => 0x95, // •
        0x2013 => 0x96, // –
        0x2014 => 0x97, // —
        0x02DC => 0x98, // ˜
        0x2122 => 0x99, // ™
        0x0161 => 0x9A, // š
        0x203A => 0x9B, // ›
        0x0153 => 0x9C, // œ
        0x017E => 0x9E, // ž
        0x0178 => 0x9F, // Ÿ
    ];

    public static function encode(string $utf8): string
    {
        $output = '';
        $i = 0;
        $len = strlen($utf8);
        while ($i < $len) {
            $byte = ord($utf8[$i]);
            if ($byte < 0x80) {
                // ASCII
                if ($byte >= 0x20 && $byte <= 0x7E) {
                    $output .= chr($byte);
                } else {
                    // Tab/CR/LF/other control — treat newline specially elsewhere;
                    // here we just drop or map. Phase 2b only encodes printable
                    // lines (newlines are split before encoding), so any remaining
                    // control char becomes '?'.
                    $output .= '?';
                }
                $i++;
                continue;
            }

            // Decode UTF-8 continuation bytes
            if (($byte & 0xE0) === 0xC0) {
                // 2-byte sequence
                if ($i + 1 >= $len) {
                    $output .= '?';
                    $i++;
                    continue;
                }
                $c1 = ord($utf8[$i + 1]);
                if (($c1 & 0xC0) !== 0x80) {
                    $output .= '?';
                    $i += 2;
                    continue;
                }
                $cp = (($byte & 0x1F) << 6) | ($c1 & 0x3F);
                $i += 2;
            } elseif (($byte & 0xF0) === 0xE0) {
                // 3-byte sequence
                if ($i + 2 >= $len) {
                    $output .= '?';
                    $i++;
                    continue;
                }
                $c1 = ord($utf8[$i + 1]);
                $c2 = ord($utf8[$i + 2]);
                if (($c1 & 0xC0) !== 0x80 || ($c2 & 0xC0) !== 0x80) {
                    $output .= '?';
                    $i += 3;
                    continue;
                }
                $cp = (($byte & 0x0F) << 12)
                    | (($c1 & 0x3F) << 6)
                    | ($c2 & 0x3F);
                $i += 3;
            } elseif (($byte & 0xF8) === 0xF0) {
                // 4-byte sequence (outside BMP — never in WinAnsi)
                $output .= '?';
                $i += 4;
                continue;
            } else {
                // Invalid leading byte
                $output .= '?';
                $i++;
                continue;
            }

            // Map codepoint to WinAnsi byte
            if ($cp >= 0xA0 && $cp <= 0xFF) {
                $output .= chr($cp);
            } elseif (isset(self::MAP[$cp])) {
                $output .= chr(self::MAP[$cp]);
            } else {
                $output .= '?';
            }
        }
        return $output;
    }
}
