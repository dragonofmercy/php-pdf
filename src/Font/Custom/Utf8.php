<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

/**
 * UTF-8 codepoint iteration helper. Single source of truth for the byte-by-byte
 * decode used by Identity-H encoding, CID width measurement, and force-break
 * splitting on the custom-TTF path.
 *
 * Invalid sequences yield [-1, 1] so callers can substitute GID 0 (.notdef)
 * the same way the standard-font path substitutes '?' for unmappable bytes.
 *
 * @internal
 */
final class Utf8
{
    /**
     * Yields [codepoint, byteLength] pairs over the input string.
     *
     * @return iterable<array{0: int, 1: int}>
     */
    public static function codepoints(string $utf8): iterable
    {
        $i = 0;
        $len = strlen($utf8);
        while ($i < $len) {
            $b0 = ord($utf8[$i]);
            if ($b0 < 0x80) {
                yield [$b0, 1];
                $i++;
            } elseif (($b0 & 0xE0) === 0xC0 && $i + 1 < $len) {
                yield [(($b0 & 0x1F) << 6) | (ord($utf8[$i + 1]) & 0x3F), 2];
                $i += 2;
            } elseif (($b0 & 0xF0) === 0xE0 && $i + 2 < $len) {
                yield [
                    (($b0 & 0x0F) << 12)
                        | ((ord($utf8[$i + 1]) & 0x3F) << 6)
                        | (ord($utf8[$i + 2]) & 0x3F),
                    3,
                ];
                $i += 3;
            } elseif (($b0 & 0xF8) === 0xF0 && $i + 3 < $len) {
                yield [
                    (($b0 & 0x07) << 18)
                        | ((ord($utf8[$i + 1]) & 0x3F) << 12)
                        | ((ord($utf8[$i + 2]) & 0x3F) << 6)
                        | (ord($utf8[$i + 3]) & 0x3F),
                    4,
                ];
                $i += 4;
            } else {
                yield [-1, 1];
                $i++;
            }
        }
    }
}
