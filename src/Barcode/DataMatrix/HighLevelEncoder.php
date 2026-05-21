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
    // Other ASCII codewords (PAD=129, FNC1=232) are introduced by later
    // tasks when they become consumed; PHPStan max rejects unused private
    // constants.
    private const int CW_ASCII_DIGIT_PAIR      = 130; // base for digit-pair packing
    private const int CW_ASCII_LATCH_C40       = 230;
    private const int CW_ASCII_LATCH_BASE256   = 231;
    private const int CW_ASCII_EXTENDED_ASCII  = 235;
    private const int CW_ASCII_LATCH_TEXT      = 239;

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

    /**
     * Map a single byte to its C40 representation (ISO 16022 Table 8).
     *
     * Returns a list of values in the C40 alphabet to emit in order, including
     * any shift codewords needed:
     *   - Shift 1 (control chars 0x00-0x1F): emit [0, byte]
     *   - Shift 2 (punctuation 0x21-0x2F, ':' to '@', '[' to '_'): emit [1, mapped]
     *   - Shift 3 (lowercase a-z + extras): emit [2, mapped]
     *   - Basic set (space, digits, uppercase): emit single value
     *   - Bytes > 0x7F: emit upper-shift escape (Shift 2 value 30) then inner mapping for byte - 128.
     *
     * @return list<int>
     */
    private static function c40Values(int $b): array
    {
        if ($b === 0x20) {
            return [3]; // space
        }
        if ($b >= 0x30 && $b <= 0x39) {
            return [$b - 0x30 + 4]; // digits 4-13
        }
        if ($b >= 0x41 && $b <= 0x5A) {
            return [$b - 0x41 + 14]; // uppercase A-Z 14-39
        }
        if ($b <= 0x1F) {
            return [0, $b]; // Shift 1
        }
        if ($b <= 0x2F) {
            return [1, $b - 0x21]; // Shift 2 punctuation 0-14 (0x21-0x2F; 0x20 handled above)
        }
        if ($b <= 0x40) {
            return [1, $b - 0x3A + 15]; // Shift 2 ':' to '@' 15-21 (0x3A-0x40; 0x30-0x39 handled above)
        }
        if ($b <= 0x5F) {
            return [1, $b - 0x5B + 22]; // Shift 2 '[' to '_' 22-26 (0x5B-0x5F; 0x41-0x5A handled above)
        }
        if ($b === 0x60) {
            return [2, 0]; // backtick -> Shift 3 value 0
        }
        if ($b <= 0x7A) {
            return [2, $b - 0x61 + 1]; // Shift 3 lowercase a-z 1-26
        }
        if ($b <= 0x7F) {
            return [2, $b - 0x7B + 27]; // Shift 3 '{' to DEL 27-31
        }
        // Bytes > 0x7F: upper-shift escape (Shift 2 value 30), then inner mapping for b - 128.
        $inner = self::c40Values($b - 128);
        return array_merge([1, 30], $inner);
    }

    /**
     * Same mapping as C40 but with lowercase in the basic set and uppercase in Shift 3.
     *
     * @return list<int>
     */
    private static function textValues(int $b): array
    {
        if ($b === 0x20) {
            return [3];
        }
        if ($b >= 0x30 && $b <= 0x39) {
            return [$b - 0x30 + 4];
        }
        if ($b >= 0x61 && $b <= 0x7A) {
            return [$b - 0x61 + 14]; // lowercase basic set 14-39
        }
        if ($b <= 0x1F) {
            return [0, $b];
        }
        if ($b <= 0x2F) {
            return [1, $b - 0x21]; // Shift 2 punctuation 0-14 (0x21-0x2F; 0x20 handled above)
        }
        if ($b <= 0x40) {
            return [1, $b - 0x3A + 15]; // Shift 2 ':' to '@' (0x3A-0x40; digits handled above)
        }
        if ($b <= 0x5A) {
            return [2, $b - 0x41 + 1]; // uppercase Shift 3 1-26 (0x41-0x5A)
        }
        if ($b <= 0x5F) {
            return [1, $b - 0x5B + 22]; // Shift 2 '[' to '_' (0x5B-0x5F)
        }
        if ($b === 0x60) {
            return [2, 0];
        }
        if ($b <= 0x7F) {
            return [2, $b - 0x7B + 27]; // Shift 3 '{' to DEL (0x7B-0x7F; lowercase handled above)
        }
        $inner = self::textValues($b - 128);
        return array_merge([1, 30], $inner);
    }

    /**
     * Encode a string in C40 mode: [230] + packed triplets + [254].
     *
     * @return list<int>
     */
    public static function encodeC40(string $input): array
    {
        return self::encodeTripletMode($input, self::CW_ASCII_LATCH_C40, self::c40Values(...));
    }

    /**
     * Encode a string in Text mode (same packing as C40, different alphabet).
     *
     * @return list<int>
     */
    public static function encodeText(string $input): array
    {
        return self::encodeTripletMode($input, self::CW_ASCII_LATCH_TEXT, self::textValues(...));
    }

    /**
     * Shared triplet packing for C40 and Text:
     *   V = 1600*a + 40*b + c + 1; emit high byte then low byte.
     *
     * If the residual is 0: emit unlatch (254).
     * If residual is 2: pad with value 0 to form a triplet, then unlatch.
     * If residual is 1: unlatch then re-emit the last input byte as ASCII (b + 1).
     *
     * @param callable(int): list<int> $mapByte
     * @return list<int>
     */
    private static function encodeTripletMode(string $input, int $latch, callable $mapByte): array
    {
        $out = [$latch];
        $values = [];
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            foreach ($mapByte(ord($input[$i])) as $v) {
                $values[] = $v;
            }
        }
        $i = 0;
        $n = count($values);
        while ($n - $i >= 3) {
            $packed = 1600 * $values[$i] + 40 * $values[$i + 1] + $values[$i + 2] + 1;
            $out[] = ($packed >> 8) & 0xFF;
            $out[] = $packed & 0xFF;
            $i += 3;
        }
        $residual = $n - $i;
        if ($residual === 0) {
            $out[] = 254;
        } elseif ($residual === 2) {
            $packed = 1600 * $values[$i] + 40 * $values[$i + 1] + 0 + 1;
            $out[] = ($packed >> 8) & 0xFF;
            $out[] = $packed & 0xFF;
            $out[] = 254;
        } else {
            // residual === 1: unlatch, then re-emit the last input byte as ASCII.
            $out[] = 254;
            $lastByte = ord($input[$len - 1]);
            $out[] = $lastByte + 1;
        }
        return $out;
    }
}
