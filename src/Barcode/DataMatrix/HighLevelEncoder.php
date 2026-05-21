<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\DataMatrix;

/**
 * DataMatrix ECC200 high-level encoder (ISO/IEC 16022 5.2).
 *
 * Walks the input string and emits a sequence of codewords. Future tasks
 * will extend this with C40, Text, Base256 and the Annex P shortest-path
 * selector. For now: pure ASCII with digit-pair packing.
 *
 * Entry point: {@see self::encode()}.
 *
 * @internal
 */
final class HighLevelEncoder
{
    // ASCII mode codewords (ISO 16022 5.2.3, Table 6).
    // Other ASCII codewords (PAD=129, LATCH_C40=230, FNC1=232, LATCH_TEXT=239)
    // are introduced by tasks 6-7 when they become consumed; PHPStan max
    // rejects unused private constants.
    private const int CW_ASCII_DIGIT_PAIR      = 130; // base for digit-pair packing
    private const int CW_ASCII_LATCH_BASE256   = 231;
    private const int CW_ASCII_EXTENDED_ASCII  = 235;

    /**
     * Encode the input string into a sequence of DataMatrix codewords.
     *
     * For now: pure ASCII with digit-pair packing. Bytes > 0x7F use the
     * extended-ASCII upper-shift codeword 235.
     *
     * @param string $input Non-empty UTF-8 byte sequence.
     * @return list<int>    Codewords (each 0-255).
     */
    public static function encode(string $input): array
    {
        return self::encodeAscii($input, 0, strlen($input));
    }

    /**
     * @return list<int>
     */
    private static function encodeAscii(string $input, int $start, int $end): array
    {
        $out = [];
        $i = $start;
        while ($i < $end) {
            if ($i + 1 < $end
                && self::isDigit($input[$i])
                && self::isDigit($input[$i + 1])
            ) {
                $pair = (int) substr($input, $i, 2);
                $out[] = self::CW_ASCII_DIGIT_PAIR + $pair;
                $i += 2;
                continue;
            }
            $b = ord($input[$i]);
            if ($b > 0x7F) {
                $out[] = self::CW_ASCII_EXTENDED_ASCII;
                $out[] = $b - 128 + 1;
            } else {
                $out[] = $b + 1;
            }
            $i++;
        }
        return $out;
    }

    private static function isDigit(string $c): bool
    {
        return $c >= '0' && $c <= '9';
    }

    /**
     * Encode a byte sequence using Base256 mode per ISO/IEC 16022 5.4.3.
     *
     * Output structure:
     *   [231]
     *   [length codeword(s)] (1 codeword if length < 250, else 2)
     *   [randomized data bytes...]
     *
     * Randomization formula (ISO 5.4.3): for codeword at position pos (1-based,
     * counting from the byte after the latch), output = (raw + ((149 * pos) % 255) + 1) mod 256.
     *
     * @return list<int>
     */
    public static function encodeBase256(string $bytes): array
    {
        $len = strlen($bytes);
        $out = [self::CW_ASCII_LATCH_BASE256];
        $pos = 1;
        if ($len < 250) {
            $out[] = self::randomize255State($len, $pos);
            $pos++;
        } else {
            $hi = intdiv($len, 250) + 249;
            $lo = $len % 250;
            $out[] = self::randomize255State($hi, $pos);
            $pos++;
            $out[] = self::randomize255State($lo, $pos);
            $pos++;
        }
        for ($i = 0; $i < $len; $i++) {
            $out[] = self::randomize255State(ord($bytes[$i]), $pos);
            $pos++;
        }
        return $out;
    }

    /**
     * Base256 randomization (ISO 5.4.3): output = (value + ((149 * pos) % 255) + 1) mod 256.
     */
    private static function randomize255State(int $value, int $position): int
    {
        $pseudoRandom = ((149 * $position) % 255) + 1;
        return ($value + $pseudoRandom) % 256;
    }
}
