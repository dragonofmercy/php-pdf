<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Aztec;

/**
 * Aztec low-level encoder: splits a raw bitstream into codewords with ISO/IEC 24778 §7.3.1.2 bit-stuffing.
 *
 * Ported faithfully from zxing-java Encoder.stuffBits (Apache 2.0):
 *   com.google.zxing.aztec.encoder.Encoder.stuffBits(BitArray bits, int wordSize)
 *
 * Algorithm:
 *   mask = (1 << wordSize) - 2
 *   Walk the input in steps of wordSize bits. For each window:
 *     - Read wordSize bits MSB-first; any bit PAST the end of the input is treated as '1'.
 *     - Assemble into an integer `word`.
 *     - If (word & mask) == mask  => top (wordSize-1) bits are all 1:
 *           emit (word & mask) i.e. force LSB to 0; advance by (wordSize-1) instead of wordSize.
 *     - If (word & mask) == 0     => top (wordSize-1) bits are all 0:
 *           emit (word | 1) i.e. force LSB to 1; advance by (wordSize-1) instead of wordSize.
 *     - Otherwise: emit word as-is, advance by wordSize.
 *
 * The "past-end = '1'" convention means the implicit padding that fills a partial trailing
 * codeword is all-ones, which keeps the stuffing logic uniform without a separate pad step.
 *
 * @internal
 */
final class LowLevelEncoder
{
    /**
     * Apply ISO/IEC 24778 §7.3.1.2 bit-stuffing to a raw bitstream.
     *
     * @param string $bits      The input bitstream as a string of '0' and '1' characters.
     * @param int    $wordSize  Codeword size in bits (6, 8, 10, or 12).
     *
     * @return list<int>  Array of integer codeword values (each in [0, 2^wordSize - 1]).
     */
    public static function stuffBits(string $bits, int $wordSize): array
    {
        $out = [];
        $n = strlen($bits);
        $mask = (1 << $wordSize) - 2;

        for ($i = 0; $i < $n; $i += $wordSize) {
            $word = 0;
            for ($j = 0; $j < $wordSize; $j++) {
                // Bits past the end of the input are treated as '1' (zxing convention).
                if ($i + $j >= $n || $bits[$i + $j] === '1') {
                    $word |= 1 << ($wordSize - 1 - $j);
                }
            }

            if (($word & $mask) === $mask) {
                // Top (wordSize-1) bits are all 1: stuff a '0' into the LSB.
                $out[] = $word & $mask;
                $i--;   // net advance = wordSize - 1 (loop will add wordSize, so subtract 1 here)
            } elseif (($word & $mask) === 0) {
                // Top (wordSize-1) bits are all 0: stuff a '1' into the LSB.
                $out[] = $word | 1;
                $i--;   // net advance = wordSize - 1
            } else {
                $out[] = $word;
            }
        }

        return $out;
    }
}
