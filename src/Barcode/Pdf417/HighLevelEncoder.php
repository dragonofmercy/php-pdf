<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Pdf417;

/**
 * PDF417 high-level encoder (ISO/IEC 15438:2001(E) annex P).
 *
 * Faithful port of zxing's PDF417HighLevelEncoder (Apache 2.0). Walks the
 * input one position at a time, choosing between Text, Byte and Numeric
 * compaction via a lookahead heuristic, and returns the DATA codewords only.
 * The symbol-length descriptor, padding and error-correction codewords are
 * added by the Encoder in a later step.
 *
 * Project-specific addition (NOT in zxing's default path): when the input is
 * valid UTF-8 and carries a non-ASCII byte, an ECI 26 (UTF-8) designator
 * [927, 26] is prepended so readers interpret the bytes as UTF-8. Raw binary
 * (not valid UTF-8) stays charset-less and flows through Byte compaction.
 * This mirrors {@see \DragonOfMercy\PhpPdf\Barcode\DataMatrix\HighLevelEncoder}.
 *
 * @internal
 */
final class HighLevelEncoder
{
    private const int TEXT_COMPACTION    = 0;
    private const int BYTE_COMPACTION    = 1;
    private const int NUMERIC_COMPACTION = 2;

    private const int SUBMODE_ALPHA       = 0;
    private const int SUBMODE_LOWER       = 1;
    private const int SUBMODE_MIXED       = 2;
    private const int SUBMODE_PUNCTUATION = 3;

    private const int LATCH_TO_TEXT        = 900;
    private const int LATCH_TO_BYTE_PADDED = 901;
    private const int LATCH_TO_NUMERIC     = 902;
    private const int SHIFT_TO_BYTE        = 913;
    private const int LATCH_TO_BYTE        = 924;
    private const int ECI_CHARSET          = 927;

    // ECI assignment number for UTF-8 (AIM ECI registry). Encoded as a single
    // codeword since it is < 900: [927, 26].
    private const int ECI_UTF8 = 26;

    /**
     * Raw code table for text compaction Mixed sub-mode (ISO 15438 4.4.2).
     *
     * @var list<int>
     */
    private const array TEXT_MIXED_RAW = [
        48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 38, 13, 9, 44, 58,
        35, 45, 46, 36, 47, 43, 37, 42, 61, 94, 0, 32, 0, 0, 0,
    ];

    /**
     * Raw code table for text compaction Punctuation sub-mode (ISO 15438 4.4.2).
     *
     * @var list<int>
     */
    private const array TEXT_PUNCTUATION_RAW = [
        59, 60, 62, 64, 91, 92, 93, 95, 96, 126, 33, 13, 9, 44, 58,
        10, 45, 46, 36, 47, 34, 124, 42, 40, 41, 63, 123, 125, 39, 0,
    ];

    /**
     * Encode the input into PDF417 data codewords.
     *
     * @return list<int> Data codewords only (each 0-928). No length
     *                   descriptor, no padding, no error correction.
     */
    public static function encode(string $input): array
    {
        $sb = [];

        if (self::shouldDeclareUtf8($input)) {
            $sb[] = self::ECI_CHARSET;
            $sb[] = self::ECI_UTF8;
        }

        $len = strlen($input);
        if ($len === 0) {
            return $sb;
        }

        $mixed = self::buildLookup(self::TEXT_MIXED_RAW);
        $punctuation = self::buildLookup(self::TEXT_PUNCTUATION_RAW);

        $p = 0;
        $textSubMode = self::SUBMODE_ALPHA;
        $encodingMode = self::TEXT_COMPACTION; // default mode, ISO 4.4.2.1

        while ($p < $len) {
            $n = self::determineConsecutiveDigitCount($input, $p);
            if ($n >= 13) {
                $sb[] = self::LATCH_TO_NUMERIC;
                $encodingMode = self::NUMERIC_COMPACTION;
                $textSubMode = self::SUBMODE_ALPHA;
                foreach (self::encodeNumeric($input, $p, $n) as $cw) {
                    $sb[] = $cw;
                }
                $p += $n;
                continue;
            }

            $t = self::determineConsecutiveTextCount($input, $p);
            // zxing latches to text on t>=5 or an all-numeric input. We add one
            // case: when already in text compaction, prefer staying in text for
            // any text-encodable run rather than latching to byte for a sub-5
            // run (no latch needed, and text packs >= as densely as byte here).
            if ($t >= 5 || $n === $len || ($encodingMode === self::TEXT_COMPACTION && $t > 0)) {
                if ($encodingMode !== self::TEXT_COMPACTION) {
                    $sb[] = self::LATCH_TO_TEXT;
                    $encodingMode = self::TEXT_COMPACTION;
                    $textSubMode = self::SUBMODE_ALPHA;
                }
                [$textSubMode, $textCw] = self::encodeText($input, $p, $t, $textSubMode, $mixed, $punctuation);
                foreach ($textCw as $cw) {
                    $sb[] = $cw;
                }
                $p += $t;
                continue;
            }

            $b = self::determineConsecutiveBinaryCount($input, $p);
            if ($b === 0) {
                $b = 1;
            }
            if ($b === 1 && $encodingMode === self::TEXT_COMPACTION) {
                // Shift for a single byte (instead of a latch).
                $byteCw = self::encodeBinary($input, $p, 1, self::TEXT_COMPACTION);
            } else {
                // Mode latch performed inside encodeBinary().
                $byteCw = self::encodeBinary($input, $p, $b, $encodingMode);
                $encodingMode = self::BYTE_COMPACTION;
                $textSubMode = self::SUBMODE_ALPHA;
            }
            foreach ($byteCw as $cw) {
                $sb[] = $cw;
            }
            $p += $b;
        }

        return $sb;
    }

    /**
     * Build the inverse lookup (byte value -> submode index), filling unknown
     * entries with -1, from a raw code table.
     *
     * @param list<int> $raw
     * @return array<int, int>
     */
    private static function buildLookup(array $raw): array
    {
        $lookup = [];
        foreach ($raw as $i => $b) {
            if ($b > 0) {
                $lookup[$b] = $i;
            }
        }
        return $lookup;
    }

    /**
     * Encode a run using Text compaction (ISO 15438 4.4.2).
     *
     * @param array<int, int> $mixed       inverse lookup for the Mixed submode
     * @param array<int, int> $punctuation inverse lookup for the Punctuation submode
     * @return array{int, list<int>} the ending text submode and the codewords
     */
    private static function encodeText(
        string $input,
        int $startpos,
        int $count,
        int $initialSubmode,
        array $mixed,
        array $punctuation,
    ): array {
        /** @var list<int> $tmp */
        $tmp = [];
        $submode = $initialSubmode;
        $idx = 0;
        while (true) {
            $ch = ord($input[$startpos + $idx]);
            switch ($submode) {
                case self::SUBMODE_ALPHA:
                    if (self::isAlphaUpper($ch)) {
                        if ($ch === 0x20) {
                            $tmp[] = 26; // space
                        } else {
                            $tmp[] = $ch - 65;
                        }
                    } else {
                        if (self::isAlphaLower($ch)) {
                            $submode = self::SUBMODE_LOWER;
                            $tmp[] = 27; // ll
                            continue 2;
                        } elseif (self::isMixed($ch, $mixed)) {
                            $submode = self::SUBMODE_MIXED;
                            $tmp[] = 28; // ml
                            continue 2;
                        } else {
                            $tmp[] = 29; // ps
                            $tmp[] = $punctuation[$ch];
                            break;
                        }
                    }
                    break;
                case self::SUBMODE_LOWER:
                    if (self::isAlphaLower($ch)) {
                        if ($ch === 0x20) {
                            $tmp[] = 26; // space
                        } else {
                            $tmp[] = $ch - 97;
                        }
                    } else {
                        if (self::isAlphaUpper($ch)) {
                            $tmp[] = 27; // as
                            $tmp[] = $ch - 65;
                            break;
                        } elseif (self::isMixed($ch, $mixed)) {
                            $submode = self::SUBMODE_MIXED;
                            $tmp[] = 28; // ml
                            continue 2;
                        } else {
                            $tmp[] = 29; // ps
                            $tmp[] = $punctuation[$ch];
                            break;
                        }
                    }
                    break;
                case self::SUBMODE_MIXED:
                    if (self::isMixed($ch, $mixed)) {
                        $tmp[] = $mixed[$ch];
                    } else {
                        if (self::isAlphaUpper($ch)) {
                            $submode = self::SUBMODE_ALPHA;
                            $tmp[] = 28; // al
                            continue 2;
                        } elseif (self::isAlphaLower($ch)) {
                            $submode = self::SUBMODE_LOWER;
                            $tmp[] = 27; // ll
                            continue 2;
                        } else {
                            if (
                                $idx + 1 < $count
                                && self::isPunctuation(ord($input[$startpos + $idx + 1]), $punctuation)
                            ) {
                                $submode = self::SUBMODE_PUNCTUATION;
                                $tmp[] = 25; // pl
                                continue 2;
                            }
                            $tmp[] = 29; // ps
                            $tmp[] = $punctuation[$ch];
                        }
                    }
                    break;
                default: // SUBMODE_PUNCTUATION
                    if (self::isPunctuation($ch, $punctuation)) {
                        $tmp[] = $punctuation[$ch];
                    } else {
                        $submode = self::SUBMODE_ALPHA;
                        $tmp[] = 29; // al
                        continue 2;
                    }
            }
            $idx++;
            if ($idx >= $count) {
                break;
            }
        }

        /** @var list<int> $out */
        $out = [];
        $h = 0;
        $tmpLen = count($tmp);
        for ($i = 0; $i < $tmpLen; $i++) {
            $odd = ($i % 2) !== 0;
            if ($odd) {
                $h = ($h * 30) + $tmp[$i];
                $out[] = $h;
            } else {
                $h = $tmp[$i];
            }
        }
        if (($tmpLen % 2) !== 0) {
            $out[] = ($h * 30) + 29; // ps
        }

        return [$submode, $out];
    }

    /**
     * Encode a byte run using Byte compaction (ISO 15438 4.4.3), with 6-byte
     * to 5-codeword packing.
     *
     * @return list<int>
     */
    private static function encodeBinary(string $input, int $startpos, int $count, int $startmode): array
    {
        $sb = [];
        if ($count === 1 && $startmode === self::TEXT_COMPACTION) {
            $sb[] = self::SHIFT_TO_BYTE;
        } elseif (($count % 6) === 0) {
            $sb[] = self::LATCH_TO_BYTE;
        } else {
            $sb[] = self::LATCH_TO_BYTE_PADDED;
        }

        $idx = $startpos;
        // Encode sixpacks: 6 bytes -> 5 base-900 codewords.
        if ($count >= 6) {
            while (($startpos + $count - $idx) >= 6) {
                // Six bytes form a 48-bit value; convert it to five base-900
                // codewords. The conversion runs as repeated division of the
                // base-256 digits so the largest intermediate stays under 2^18
                // (899 * 256 + 255) - no 48-bit accumulator, correct on 32-bit PHP.
                $work = [
                    ord($input[$idx]),
                    ord($input[$idx + 1]),
                    ord($input[$idx + 2]),
                    ord($input[$idx + 3]),
                    ord($input[$idx + 4]),
                    ord($input[$idx + 5]),
                ];
                $chars = [];
                for ($k = 0; $k < 5; $k++) {
                    $remainder = 0;
                    for ($j = 0; $j < 6; $j++) {
                        $cur = ($remainder << 8) + $work[$j];
                        $work[$j] = intdiv($cur, 900);
                        $remainder = $cur % 900;
                    }
                    $chars[] = $remainder;
                }
                // The base-900 digits are produced least-significant first; emit
                // them most-significant first.
                foreach (array_reverse($chars) as $cw) {
                    $sb[] = $cw;
                }
                $idx += 6;
            }
        }
        // Encode the remaining n<6 bytes verbatim (one codeword per byte).
        for ($i = $idx; $i < $startpos + $count; $i++) {
            $sb[] = ord($input[$i]);
        }

        return $sb;
    }

    /**
     * Encode a digit run using Numeric compaction (ISO 15438 4.4.4): base-900
     * packing of up to 44 digits at a time, prefixed with a leading 1.
     *
     * @return list<int>
     */
    private static function encodeNumeric(string $input, int $startpos, int $count): array
    {
        $sb = [];
        $idx = 0;
        while ($idx < $count) {
            $len = min(44, $count - $idx);
            $part = '1' . substr($input, $startpos + $idx, $len);
            // Base-900 conversion via repeated division on the decimal string,
            // dependency-free (no GMP/bcmath required).
            $tmp = [];
            $digits = $part;
            do {
                [$digits, $remainder] = self::divModBy900($digits);
                $tmp[] = $remainder;
            } while ($digits !== '0');

            for ($i = count($tmp) - 1; $i >= 0; $i--) {
                $sb[] = $tmp[$i];
            }
            $idx += $len;
        }

        return $sb;
    }

    /**
     * Divide a non-negative decimal string by 900, returning [quotient, remainder].
     * The quotient is a normalized decimal string ('0' when zero).
     *
     * @return array{string, int}
     */
    private static function divModBy900(string $decimal): array
    {
        $quotient = '';
        $remainder = 0;
        $len = strlen($decimal);
        for ($i = 0; $i < $len; $i++) {
            $cur = $remainder * 10 + (ord($decimal[$i]) - 48);
            $quotient .= (string) intdiv($cur, 900);
            $remainder = $cur % 900;
        }
        $quotient = ltrim($quotient, '0');
        if ($quotient === '') {
            $quotient = '0';
        }
        return [$quotient, $remainder];
    }

    private static function isDigit(int $ch): bool
    {
        return $ch >= 0x30 && $ch <= 0x39;
    }

    private static function isAlphaUpper(int $ch): bool
    {
        return $ch === 0x20 || ($ch >= 0x41 && $ch <= 0x5A);
    }

    private static function isAlphaLower(int $ch): bool
    {
        return $ch === 0x20 || ($ch >= 0x61 && $ch <= 0x7A);
    }

    /**
     * @param array<int, int> $mixed
     */
    private static function isMixed(int $ch, array $mixed): bool
    {
        return isset($mixed[$ch]);
    }

    /**
     * @param array<int, int> $punctuation
     */
    private static function isPunctuation(int $ch, array $punctuation): bool
    {
        return isset($punctuation[$ch]);
    }

    private static function isText(int $ch): bool
    {
        return $ch === 0x09 || $ch === 0x0A || $ch === 0x0D || ($ch >= 32 && $ch <= 126);
    }

    /**
     * Number of consecutive digits starting at $startpos.
     */
    private static function determineConsecutiveDigitCount(string $input, int $startpos): int
    {
        $len = strlen($input);
        $idx = $startpos;
        while ($idx < $len && self::isDigit(ord($input[$idx]))) {
            $idx++;
        }
        return $idx - $startpos;
    }

    /**
     * Number of consecutive text-encodable characters starting at $startpos.
     */
    private static function determineConsecutiveTextCount(string $input, int $startpos): int
    {
        $len = strlen($input);
        $idx = $startpos;
        while ($idx < $len) {
            $numericCount = 0;
            while ($numericCount < 13 && $idx < $len && self::isDigit(ord($input[$idx]))) {
                $numericCount++;
                $idx++;
            }
            if ($numericCount >= 13) {
                return $idx - $startpos - $numericCount;
            }
            if ($numericCount > 0) {
                continue;
            }
            if (!self::isText(ord($input[$idx]))) {
                break;
            }
            $idx++;
        }
        return $idx - $startpos;
    }

    /**
     * Number of consecutive binary-encodable characters starting at $startpos.
     */
    private static function determineConsecutiveBinaryCount(string $input, int $startpos): int
    {
        $len = strlen($input);
        $idx = $startpos;
        while ($idx < $len) {
            // A run of 13+ digits is better served by Numeric compaction.
            $numericCount = 0;
            while (
                $numericCount < 13
                && $idx + $numericCount < $len
                && self::isDigit(ord($input[$idx + $numericCount]))
            ) {
                $numericCount++;
            }
            if ($numericCount >= 13) {
                return $idx - $startpos;
            }
            // A run of 5+ text characters is better served by Text compaction;
            // ending the binary run there keeps that text out of Byte mode.
            $textCount = 0;
            while (
                $textCount < 5
                && $idx + $textCount < $len
                && self::isText(ord($input[$idx + $textCount]))
            ) {
                $textCount++;
            }
            if ($textCount >= 5) {
                return $idx - $startpos;
            }
            $idx++;
        }
        return $idx - $startpos;
    }

    /**
     * True when the input is valid UTF-8 and carries at least one non-ASCII
     * byte; such payloads get an ECI 26 (UTF-8) prefix. Raw binary stays
     * charset-less. Mirrors the DataMatrix encoder's ECI gate.
     */
    private static function shouldDeclareUtf8(string $input): bool
    {
        $len = strlen($input);
        $hasNonAscii = false;
        for ($i = 0; $i < $len; $i++) {
            if (ord($input[$i]) > 0x7F) {
                $hasNonAscii = true;
                break;
            }
        }
        return $hasNonAscii && mb_check_encoding($input, 'UTF-8');
    }
}
